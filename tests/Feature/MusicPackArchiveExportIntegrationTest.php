<?php

use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Liberu\Foundation\Files\Contracts\MalwareScanner;
use Liberu\Foundation\ImportExport\Contracts\TransferAuthorizer;
use Liberu\Music\MediaLibrary\Commands\SaveMediaPack;
use Liberu\Music\MediaLibrary\Commands\StartUpload;
use Liberu\Music\MediaLibrary\Exceptions\ArchiveResultExpired;
use Liberu\Music\MediaLibrary\MusicMediaLibraryServiceProvider;
use Liberu\Music\MediaLibrary\Services\ArchiveExports;
use Liberu\Music\MediaLibrary\Services\MediaPacks;
use Liberu\Music\MediaLibrary\Services\MediaUploads;
use Liberu\Music\MediaLibraryApi\MusicMediaLibraryApiServiceProvider;

it('exports a reviewed pack on a canonical host worker and expires only its private artifact', function () {
    $this->app->register(MusicMediaLibraryServiceProvider::class);
    $this->app->register(MusicMediaLibraryApiServiceProvider::class);
    app('router')->getRoutes()->refreshNameLookups();
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
    $token = $actor->createToken('archive-export', ['music.media.read', 'music.media.packs.export', 'music.media.archives.export'])->plainTextToken;
    $this->withToken($token);
    $taskId = (string) Str::uuid();
    $base = '/api/v1/music-media/workspaces/studio';
    $taskUrl = $base.'/archive-export-tasks/'.$taskId;
    $this->withHeader('Idempotency-Key', $taskId)->putJson($base.'/packs/'.$pack.'/archive-export-tasks/'.$taskId, ['version' => 1])->assertAccepted();
    $this->getJson($taskUrl)->assertSuccessful()->assertJsonPath('data.status', 'queued');
    $this->getJson($taskUrl.'/result')->assertAccepted();
    $jobs->process($taskId);
    $receipt = $this->getJson($taskUrl.'/result')->assertSuccessful()->assertJsonPath('data.media_objects', 1);
    $grant = $this->getJson($receipt->json('links.download_url'))->assertSuccessful();
    $response = $this->get($grant->json('data.url'))->assertSuccessful()->assertHeader('Content-Type', 'application/zip');
    expect(hash('sha256', $response->streamedContent()))->toBe($receipt->json('data.sha256'));
    $this->getJson($taskUrl)->assertSuccessful()->assertJsonPath('data.status', 'ready');
    $this->travel(3601)->seconds();
    expect(fn () => $jobs->open($actor, 'studio', $taskId))->toThrow(ArchiveResultExpired::class);
    expect($jobs->cleanup($actor, 'studio'))->toBe(1)->and($jobs->get($actor, 'studio', $taskId)->status)->toBe('expired')
        ->and($uploads->get($actor, 'studio', $asset)->id)->toBe($asset)
        ->and($disk->files('music/archive-exports/'.hash('sha256', 'studio')))->toBe([]);
    $this->travelBack();
    $actor->tokens()->delete();
    app('auth')->forgetGuards();
    $this->getJson($taskUrl)->assertUnauthorized();
    $this->get($grant->json('data.url'))->assertUnauthorized();
});
