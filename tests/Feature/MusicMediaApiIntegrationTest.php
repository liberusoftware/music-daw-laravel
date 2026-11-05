<?php

use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Liberu\Foundation\Files\Contracts\MalwareScanner;
use Liberu\Music\MediaLibrary\Commands\StartUpload;
use Liberu\Music\MediaLibrary\MusicMediaLibraryServiceProvider;
use Liberu\Music\MediaLibrary\Services\MediaUploads;
use Liberu\Music\MediaLibrary\Services\WaveformProcessing;
use Liberu\Music\MediaLibraryApi\MusicMediaLibraryApiServiceProvider;

it('uses the host user provider and core policies through the optional media API', function () {
    $this->app->register(MusicMediaLibraryServiceProvider::class);
    $this->app->register(MusicMediaLibraryApiServiceProvider::class);
    app('router')->getRoutes()->refreshNameLookups();
    $this->artisan('migrate', ['--path' => base_path('modules/music-media-library/database/migrations'), '--realpath' => true])->assertSuccessful();
    config()->set('music-media.disk', 'host-media-api');
    Storage::fake('host-media-api');
    $actor = User::factory()->create();
    $allowed = true;
    foreach (['upload', 'read', 'list', 'quota_read'] as $operation) {
        Gate::define('music.media.'.$operation, function ($user, $workspace) use ($actor, &$allowed) {
            return $allowed && $user->getKey() === $actor->getKey() && $workspace === 'studio';
        });
    }
    app()->instance(MalwareScanner::class, Mockery::mock(MalwareScanner::class)->shouldReceive('clean')->once()->andReturnTrue()->getMock());
    $format = pack('vvVVvv', 1, 1, 48000, 96000, 2, 16);
    $chunks = 'fmt '.pack('V', 16).$format.'data'.pack('V', 4).pack('v*', 0, 16384);
    $bytes = 'RIFF'.pack('V', strlen($chunks) + 4).'WAVE'.$chunks;
    $assetId = (string) Str::uuid();
    $uploads = app(MediaUploads::class);
    $uploads->start($actor, new StartUpload('studio', $assetId, 'Original', strlen($bytes), hash('sha256', $bytes), []));
    $uploads->append($actor, 'studio', $assetId, 0, $bytes);
    $uploads->complete($actor, 'studio', $assetId);
    Bus::fake();
    Sanctum::actingAs($actor, ['music.media.read', 'music.media.process', 'music.media.quota.read']);
    $base = '/api/v1/music-media/workspaces/studio';
    $this->getJson($base.'/assets')->assertSuccessful()->assertJsonPath('data.0.id', $assetId);
    $this->getJson($base.'/upload-budget')->assertSuccessful()->assertJsonPath('data.reserved_bytes', 96);
    $taskId = (string) Str::uuid();
    $taskUrl = $base.'/waveform-tasks/'.$taskId;
    $this->withHeader('Idempotency-Key', $taskId)->putJson($taskUrl, ['asset_id' => $assetId, 'sample_frames_per_peak' => 1])->assertAccepted();
    app(WaveformProcessing::class)->process($taskId);
    $this->getJson($taskUrl.'/result')->assertSuccessful()->assertJsonPath('data.source_sha256', hash('sha256', $bytes));
    $cancelId = (string) Str::uuid();
    $cancelUrl = $base.'/waveform-tasks/'.$cancelId;
    $this->withHeader('Idempotency-Key', $cancelId)->putJson($cancelUrl, ['asset_id' => $assetId])->assertAccepted();
    $this->deleteJson($cancelUrl)->assertSuccessful()->assertJsonPath('data.status', 'cancelled');
    app(WaveformProcessing::class)->process($cancelId);
    $this->getJson($cancelUrl.'/result')->assertConflict();
    $allowed = false;
    $this->getJson($base.'/assets/'.$assetId)->assertForbidden();
});
