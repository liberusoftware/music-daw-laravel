<?php

use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Liberu\Foundation\Files\Contracts\MalwareScanner;
use Liberu\Foundation\ImportExport\Contracts\TransferAuthorizer;
use Liberu\Music\MediaLibrary\Commands\RetainAssets;
use Liberu\Music\MediaLibrary\Data\MediaPack;
use Liberu\Music\MediaLibrary\MusicMediaLibraryServiceProvider;
use Liberu\Music\MediaLibrary\Services\AssetProcessing;
use Liberu\Music\MediaLibrary\Services\MediaRetention;
use Liberu\Music\MediaLibrary\Services\WaveformProcessing;
use Liberu\Music\MediaLibraryApi\MusicMediaLibraryApiServiceProvider;

it('imports indexes tags finds and restores retained audio through the host HTTP adapter', function () {
    $this->app->register(MusicMediaLibraryServiceProvider::class);
    $this->app->register(MusicMediaLibraryApiServiceProvider::class);
    app('router')->getRoutes()->refreshNameLookups();
    $this->artisan('migrate', ['--path' => base_path('modules/music-media-library/database/migrations'), '--realpath' => true])->assertSuccessful();
    config()->set('music-media.disk', 'host-media-import-recovery');
    $disk = Storage::fake('host-media-import-recovery');
    $actor = User::factory()->create();
    foreach (['upload', 'read', 'list', 'annotate', 'restore', 'retain', 'download', 'pack_read', 'pack_write', 'pack_import', 'pack_export', 'distribute'] as $operation) {
        Gate::define('music.media.'.$operation, fn ($user, $workspace) => $user->getKey() === $actor->getKey() && $workspace === 'studio');
    }
    app()->instance(MalwareScanner::class, Mockery::mock(MalwareScanner::class)->shouldReceive('clean')->twice()->andReturnTrue()->getMock());
    Bus::fake();
    $token = $actor->createToken('music-import', ['music.media.read', 'music.media.upload', 'music.media.edit', 'music.media.process', 'music.media.restore', 'music.media.download', 'music.media.packs.read', 'music.media.packs.write', 'music.media.packs.import', 'music.media.packs.export'])->plainTextToken;
    $this->withToken($token);
    $format = pack('vvVVvv', 1, 1, 48000, 96000, 2, 16);
    $chunks = 'fmt '.pack('V', 16).$format.'data'.pack('V', 4).pack('v*', 0, 16384);
    $bytes = 'RIFF'.pack('V', strlen($chunks) + 4).'WAVE'.$chunks;
    $checksum = hash('sha256', $bytes);
    $base = '/api/v1/music-media/workspaces/studio';
    $rights = ['source' => 'Original takes', 'license' => 'CC-BY-4.0', 'attribution' => 'Artist'];
    $receive = function (string $id) use ($base, $bytes, $checksum, $token, $rights): void {
        $this->withHeader('Idempotency-Key', $id)->putJson($base.'/uploads/'.$id, ['name' => 'Take.wav', 'bytes' => strlen($bytes), 'sha256' => $checksum, 'provenance' => $rights])->assertSuccessful();
        $this->call('PUT', $base.'/uploads/'.$id.'/chunks/0', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$token, 'CONTENT_TYPE' => 'application/octet-stream', 'HTTP_X_CHUNK_SHA256' => $checksum,
            'HTTP_IDEMPOTENCY_KEY' => $id.':0:'.$checksum], $bytes)->assertSuccessful();
    };
    $assetId = (string) Str::uuid();
    $receive($assetId);
    $taskId = (string) Str::uuid();
    $this->withHeader('Idempotency-Key', $taskId)->putJson($base.'/verification-tasks/'.$taskId, ['upload_id' => $assetId])->assertAccepted();
    $this->getJson($base.'/assets/'.$assetId)->assertNotFound();
    app(AssetProcessing::class)->process($taskId);
    $this->getJson($base.'/asset-tasks/'.$taskId.'/result')->assertSuccessful()->assertJsonPath('data.id', $assetId)->assertJsonPath('data.frames', 2);
    $waveformId = (string) Str::uuid();
    $this->withHeader('Idempotency-Key', $waveformId)->putJson($base.'/waveform-tasks/'.$waveformId, ['asset_id' => $assetId, 'sample_frames_per_peak' => 1])->assertAccepted();
    app(WaveformProcessing::class)->process($waveformId);
    $this->getJson($base.'/waveform-tasks/'.$waveformId.'/result')->assertSuccessful()->assertJsonCount(2, 'data.peaks');
    $this->getJson($base.'/assets/'.$assetId)->assertSuccessful()->assertHeader('ETag', '"0"');
    $this->withHeaders(['Idempotency-Key' => 'label', 'If-Match' => '"0"'])->putJson($base.'/assets/'.$assetId.'/metadata', ['name' => 'Kick', 'tags' => ['Drums']])->assertSuccessful()->assertHeader('ETag', '"1"');
    $this->getJson($base.'/assets?filter[tags][0]=drums')->assertSuccessful()->assertJsonPath('data.0.name', 'Kick');
    app(MediaRetention::class)->retain($actor, new RetainAssets('studio', 'revision:1', [$assetId]));
    $disk->delete('music/assets/'.hash('sha256', 'studio').'/'.$checksum);
    $this->getJson($base.'/assets/'.$assetId)->assertSuccessful()->assertJsonPath('data.available', false);
    $replacement = (string) Str::uuid();
    $receive($replacement);
    $restoration = (string) Str::uuid();
    $this->withHeader('Idempotency-Key', $restoration)->putJson($base.'/assets/'.$assetId.'/restoration-tasks/'.$restoration, ['replacement_upload_id' => $replacement])->assertAccepted();
    app(AssetProcessing::class)->process($restoration);
    $this->getJson($base.'/asset-tasks/'.$restoration.'/result')->assertSuccessful()->assertJsonPath('data.id', $assetId);
    $this->getJson($base.'/assets/'.$assetId)->assertSuccessful()->assertJsonPath('data.name', 'Kick')->assertJsonPath('data.available', true)->assertHeader('ETag', '"1"');
    expect(app(MediaRetention::class)->isProtected($actor, 'studio', $assetId))->toBeTrue();
    app()->instance(TransferAuthorizer::class,
        Mockery::mock(TransferAuthorizer::class)->shouldReceive('allowed')->with((string) $actor->getKey(), Mockery::any(), Mockery::any())->andReturnTrue()->getMock());
    $url = $this->getJson($base.'/assets/'.$assetId.'/download-url')->assertSuccessful()->json('data.url');
    expect($this->get($url)->assertSuccessful()->streamedContent())->toBe($bytes);
    Gate::define('music.media.pack_distribute', fn ($user, MediaPack $pack) => $user->getKey() === $actor->getKey() && $pack->workspaceId === 'studio');
    $packUrl = $base.'/packs/'.Str::uuid();
    $this->withHeaders(['Idempotency-Key' => 'pack-create', 'If-Match' => '"0"'])->putJson($packUrl, ['name' => 'Studio takes', 'asset_ids' => [$assetId], 'rights' => $rights])->assertSuccessful()->assertHeader('ETag', '"1"');
    $manifest = $this->getJson($packUrl.'/export')->assertSuccessful()->json('data');
    $destination = $base.'/packs/'.Str::uuid();
    $import = ['manifest' => $manifest, 'asset_map' => [$assetId => $assetId]];
    $this->postJson($destination.'/import-preview', $import)->assertSuccessful();
    $this->withHeader('Idempotency-Key', 'pack-import')->putJson($destination.'/import', $import)->assertSuccessful();
    $this->getJson($destination)->assertSuccessful()->assertJsonPath('data.assets.0.reference.id', $assetId);
    $actor->tokens()->delete();
    app('auth')->forgetGuards();
    $this->getJson($base.'/assets/'.$assetId)->assertUnauthorized();
    $this->get($url)->assertUnauthorized();
    $this->getJson($packUrl)->assertUnauthorized();
});
