<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Liberu\Foundation\Files\Contracts\MalwareScanner;
use Liberu\Music\MediaLibrary\Commands\CollectTrashedAssets;
use Liberu\Music\MediaLibrary\Commands\RecoverTrashedAsset;
use Liberu\Music\MediaLibrary\Commands\RetainAssets;
use Liberu\Music\MediaLibrary\Commands\StartUpload;
use Liberu\Music\MediaLibrary\Commands\TrashAssets;
use Liberu\Music\MediaLibrary\Events\AssetLifecycleChanged;
use Liberu\Music\MediaLibrary\Exceptions\UploadConflict;
use Liberu\Music\MediaLibrary\MusicMediaLibraryServiceProvider;
use Liberu\Music\MediaLibrary\Services\AssetGarbageCollection;
use Liberu\Music\MediaLibrary\Services\AssetLifecycle;
use Liberu\Music\MediaLibrary\Services\MediaRetention;
use Liberu\Music\MediaLibrary\Services\MediaUploads;
use Liberu\Music\MediaLibrary\Services\UploadBudgets;

// Collection commits durable intent before file I/O. This host-container test
// intentionally runs outside Feature's RefreshDatabase transaction wrapper.
it('collects through the host container while preserving shared referenced content', function () {
    $this->app->register(MusicMediaLibraryServiceProvider::class);
    $this->artisan('migrate')->assertSuccessful();
    config()->set('music-media.disk', 'host-media-collection');
    $disk = Storage::fake('host-media-collection');
    $actor = User::factory()->create();
    foreach (['upload', 'read', 'trash', 'restore', 'retain', 'collect', 'quota_read'] as $operation) {
        Gate::define('music.media.'.$operation, fn ($user, $workspace) => $user->getKey() === $actor->getKey() && $workspace === 'studio');
    }
    app()->instance(MalwareScanner::class, Mockery::mock(MalwareScanner::class)->shouldReceive('clean')->times(3)->andReturnTrue()->getMock());
    $format = pack('vvVVvv', 1, 1, 48000, 96000, 2, 16);
    $chunks = 'fmt '.pack('V', 16).$format.'data'.pack('V', 4).pack('v*', 0, 16384);
    $bytes = 'RIFF'.pack('V', strlen($chunks) + 4).'WAVE'.$chunks;
    $uploads = app(MediaUploads::class);
    $publish = function (string $audio) use ($uploads, $actor): string {
        $id = (string) Str::uuid();
        $uploads->start($actor, new StartUpload('studio', $id, 'Original.wav', strlen($audio), hash('sha256', $audio), []));
        $uploads->append($actor, 'studio', $id, 0, $audio);
        $uploads->complete($actor, 'studio', $id);

        return $id;
    };
    $unused = $publish($bytes);
    $retained = $publish($bytes);
    $different = substr_replace($bytes, pack('v', 1), -2);
    $separate = $publish($different);
    app(MediaRetention::class)->retain($actor, new RetainAssets('studio', 'pack:1:revision:1', [$retained], 'pack'));
    $lifecycle = app(AssetLifecycle::class);
    expect(fn () => $lifecycle->trash($actor, new TrashAssets('studio', [$retained], 'protected')))->toThrow(UploadConflict::class);
    $lifecycle->trash($actor, new TrashAssets('studio', [$separate], 'temporary'));
    $lifecycle->recover($actor, new RecoverTrashedAsset('studio', $separate, 'undo'));
    $lifecycle->trash($actor, new TrashAssets('studio', [$unused, $separate], 'unused'));
    $this->travel(24)->hours();
    $collector = app(AssetGarbageCollection::class);
    $preview = $collector->collect($actor, new CollectTrashedAssets('studio'));
    expect($preview->assetIds)->toHaveCount(2)->and($preview->declaredBytes)->toBe(96)
        ->and(app(UploadBudgets::class)->get($actor, 'studio')->reservedBytes)->toBe(288);
    $committedStates = [];
    app('events')->listen(AssetLifecycleChanged::class, function ($event) use (&$committedStates) {
        $committedStates[] = [$event->change->status, DB::connection()->transactionLevel()];
    });
    $result = $collector->collect($actor, new CollectTrashedAssets('studio', dryRun: false));
    expect($result->assetIds)->toHaveCount(2)->and($uploads->get($actor, 'studio', $retained)->metadata['sha256'])->toBe(hash('sha256', $bytes))
        ->and($disk->exists('music/assets/'.hash('sha256', 'studio').'/'.hash('sha256', $bytes)))->toBeTrue()
        ->and($disk->exists('music/assets/'.hash('sha256', 'studio').'/'.hash('sha256', $different)))->toBeFalse()
        ->and(app(UploadBudgets::class)->get($actor, 'studio')->reservedBytes)->toBe(96)
        ->and(array_column($committedStates, 1))->toBe([0, 0, 0]);
    expect($collector->collect($actor, new CollectTrashedAssets('studio', dryRun: false))->assetIds)->toBe([]);
});
