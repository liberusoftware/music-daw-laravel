<?php

use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Liberu\Foundation\Files\Contracts\MalwareScanner;
use Liberu\Foundation\ImportExport\Contracts\TransferAuthorizer;
use Liberu\Music\MediaLibrary\Commands\RequestArchiveExport;
use Liberu\Music\MediaLibrary\Commands\SaveMediaPack;
use Liberu\Music\MediaLibrary\Commands\StartUpload;
use Liberu\Music\MediaLibrary\Exceptions\ArchiveResultExpired;
use Liberu\Music\MediaLibrary\MusicMediaLibraryServiceProvider;
use Liberu\Music\MediaLibrary\Services\ArchiveExports;
use Liberu\Music\MediaLibrary\Services\MediaPacks;
use Liberu\Music\MediaLibrary\Services\MediaUploads;

it('exports a reviewed pack on a canonical host worker and expires only its private artifact', function () {
    $this->app->register(MusicMediaLibraryServiceProvider::class);
    $this->artisan('migrate', ['--path' => base_path('modules/music-media-library/database/migrations'), '--realpath' => true])->assertSuccessful();
    config()->set('music-media.disk', 'host-queued-archive');
    $disk = Storage::fake('host-queued-archive');
    $actor = User::factory()->create();
    foreach (['upload', 'read', 'retain', 'pack_write', 'pack_read', 'pack_export', 'distribute', 'archive_cleanup'] as $operation) {
        Gate::define('music.media.'.$operation, fn ($user, $workspace) => $user->getKey() === $actor->getKey() && $workspace === 'studio');
    }
    Gate::define('music.media.pack_distribute', fn ($user, $pack) => $user->getKey() === $actor->getKey() && $pack->workspaceId === 'studio');
    app()->instance(MalwareScanner::class, Mockery::mock(MalwareScanner::class)->shouldReceive('clean')->andReturnTrue()->getMock());
    app()->instance(TransferAuthorizer::class, Mockery::mock(TransferAuthorizer::class)->shouldReceive('allowed')->with((string) $actor->getKey(), 'music-media-pack', 'export')->andReturnTrue()->getMock());
    Bus::fake();
    $format = pack('vvVVvv', 1, 1, 48000, 96000, 2, 16);
    $data = 'fmt '.pack('V', 16).$format.'data'.pack('V', 4).pack('v*', 0, 16384);
    $bytes = 'RIFF'.pack('V', strlen($data) + 4).'WAVE'.$data;
    $asset = (string) Str::uuid();
    $rights = ['source' => 'Original takes', 'license' => 'CC-BY-4.0', 'attribution' => 'Artist'];
    $uploads = app(MediaUploads::class);
    $uploads->start($actor, new StartUpload('studio', $asset, 'Take', strlen($bytes), hash('sha256', $bytes), $rights));
    $uploads->append($actor, 'studio', $asset, 0, $bytes);
    $uploads->complete($actor, 'studio', $asset);
    $pack = (string) Str::uuid();
    app(MediaPacks::class)->save($actor, new SaveMediaPack('studio', $pack, 'Studio originals', [$asset], $rights, 0, 'save'));
    $jobs = app(ArchiveExports::class);
    $task = $jobs->request($actor, new RequestArchiveExport('studio', (string) Str::uuid(), $pack, 1));
    expect($task->status)->toBe('queued')->and($jobs->result($actor, 'studio', $task->id))->toBeNull();
    $jobs->process($task->id);
    $result = $jobs->result($actor, 'studio', $task->id);
    $stream = $jobs->open($actor, 'studio', $task->id);
    try {
        expect(hash('sha256', stream_get_contents($stream)))->toBe($result->sha256)->and($result->mediaObjects)->toBe(1);
    } finally {
        fclose($stream);
    }
    expect($jobs->get($actor, 'studio', $task->id)->status)->toBe('ready');
    $this->travel(3601)->seconds();
    expect(fn () => $jobs->open($actor, 'studio', $task->id))->toThrow(ArchiveResultExpired::class);
    expect($jobs->cleanup($actor, 'studio'))->toBe(1)->and($jobs->get($actor, 'studio', $task->id)->status)->toBe('expired')
        ->and($uploads->get($actor, 'studio', $asset)->id)->toBe($asset)
        ->and($disk->files('music/archive-exports/'.hash('sha256', 'studio')))->toBe([]);
    $this->travelBack();
});
