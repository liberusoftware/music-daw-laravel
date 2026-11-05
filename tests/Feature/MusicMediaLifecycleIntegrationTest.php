<?php

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Liberu\Foundation\Files\Contracts\MalwareScanner;
use Liberu\Music\MediaLibrary\Commands\CollectTrashedAssets;
use Liberu\Music\MediaLibrary\Commands\ListTrashedAssets;
use Liberu\Music\MediaLibrary\Commands\RecoverTrashedAsset;
use Liberu\Music\MediaLibrary\Commands\RetainAssets;
use Liberu\Music\MediaLibrary\Commands\StartUpload;
use Liberu\Music\MediaLibrary\Commands\TrashAssets;
use Liberu\Music\MediaLibrary\Exceptions\UploadConflict;
use Liberu\Music\MediaLibrary\MusicMediaLibraryServiceProvider;
use Liberu\Music\MediaLibrary\Services\AssetGarbageCollection;
use Liberu\Music\MediaLibrary\Services\AssetLifecycle;
use Liberu\Music\MediaLibrary\Services\MediaRetention;
use Liberu\Music\MediaLibrary\Services\MediaUploads;
use Liberu\Music\MediaLibrary\Services\UploadBudgets;

it('wires recoverable asset trash through host policies without releasing retained media or reservations', function () {
    $this->app->register(MusicMediaLibraryServiceProvider::class);
    $this->artisan('migrate', ['--path' => base_path('modules/music-media-library/database/migrations'), '--realpath' => true])->assertSuccessful();
    config()->set('music-media.disk', 'host-media-lifecycle');
    Storage::fake('host-media-lifecycle');
    $actor = User::factory()->create();
    foreach (['upload', 'read', 'trash', 'trash_list', 'restore', 'retain', 'collect', 'quota_read'] as $operation) {
        Gate::define('music.media.'.$operation, fn ($user, $workspace) => $user->getKey() === $actor->getKey() && $workspace === 'studio');
    }
    $scanner = Mockery::mock(MalwareScanner::class);
    $scanner->shouldReceive('clean')->twice()->andReturnTrue();
    app()->instance(MalwareScanner::class, $scanner);
    $format = pack('vvVVvv', 1, 1, 48000, 96000, 2, 16);
    $chunks = 'fmt '.pack('V', 16).$format.'data'.pack('V', 4).pack('v*', 0, 16384);
    $bytes = 'RIFF'.pack('V', strlen($chunks) + 4).'WAVE'.$chunks;
    $uploads = app(MediaUploads::class);
    $ids = [(string) Str::uuid(), (string) Str::uuid()];
    foreach ($ids as $id) {
        $uploads->start($actor, new StartUpload('studio', $id, 'Original.wav', strlen($bytes), hash('sha256', $bytes), []));
        $uploads->append($actor, 'studio', $id, 0, $bytes);
        $uploads->complete($actor, 'studio', $id);
    }
    app(MediaRetention::class)->retain($actor, new RetainAssets('studio', 'session:1:revision:1', [$ids[0]]));
    $lifecycle = app(AssetLifecycle::class);
    expect(fn () => $lifecycle->trash($actor, new TrashAssets('studio', [$ids[0]], 'protected')))->toThrow(UploadConflict::class);
    $lifecycle->trash($actor, new TrashAssets('studio', [$ids[1]], 'trash'));
    expect($lifecycle->list($actor, new ListTrashedAssets('studio'))->items[0]->id)->toBe($ids[1])
        ->and(app(MediaRetention::class)->isProtected($actor, 'studio', $ids[0]))->toBeTrue()
        ->and(app(UploadBudgets::class)->get($actor, 'studio')->reservedBytes)->toBe(192);
    $lifecycle->recover($actor, new RecoverTrashedAsset('studio', $ids[1], 'undo'));
    expect($uploads->get($actor, 'studio', $ids[1])->metadata['sha256'])->toBe(hash('sha256', $bytes));
    // Host integration tests run inside RefreshDatabase's outer transaction.
    expect(fn () => app(AssetGarbageCollection::class)->collect($actor, new CollectTrashedAssets('studio', dryRun: false)))->toThrow(LogicException::class);
});
