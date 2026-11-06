<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Contracts\Events\Dispatcher as Events;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Liberu\Foundation\Files\Contracts\MalwareScanner;
use Liberu\Foundation\ImportExport\Contracts\TransferAuthorizer;
use Liberu\Music\MediaLibrary\Commands\SaveMediaPack;
use Liberu\Music\MediaLibrary\Commands\StartUpload;
use Liberu\Music\MediaLibrary\Events\AssetReady;
use Liberu\Music\MediaLibrary\Exceptions\UploadConflict;
use Liberu\Music\MediaLibrary\Jobs\ImportPackArchiveTask;
use Liberu\Music\MediaLibrary\MusicMediaLibraryServiceProvider;
use Liberu\Music\MediaLibrary\Services\ArchiveImports;
use Liberu\Music\MediaLibrary\Services\AssetProcessing;
use Liberu\Music\MediaLibrary\Services\MediaPacks;
use Liberu\Music\MediaLibrary\Services\MediaUploads;
use Liberu\Music\MediaLibrary\Services\PackArchives;
use Liberu\Music\MediaLibraryApi\Tests\Fixtures\Actor;

beforeEach(function () {
    $this->app->register(MusicMediaLibraryServiceProvider::class);
    $path = dirname((new ReflectionClass(MusicMediaLibraryServiceProvider::class))->getFileName(), 2).'/database/migrations';
    $this->artisan('migrate', ['--path' => $path, '--realpath' => true])->assertSuccessful();
    config()->set('music-media.disk', 'api-archive-imports');
    $this->disk = Storage::fake('api-archive-imports');
    $this->actor = (new Actor())->forceFill(['id' => 7]);
    $this->denied = null;
    foreach (['upload', 'read', 'retain', 'pack_write', 'pack_read', 'pack_export', 'pack_import', 'distribute'] as $operation) {
        app(Gate::class)->define('music.media.'.$operation, fn ($actor, $workspace) => in_array($actor->id, [7, 8]) && in_array($workspace, ['origin', 'studio', 'other']) && $this->denied !== $operation);
    }
    app(Gate::class)->define('music.media.pack_distribute', fn () => true);
    $this->allowed = true;
    app()->instance(TransferAuthorizer::class, Mockery::mock(TransferAuthorizer::class)->shouldReceive('allowed')->with(Mockery::any(), 'music-media-pack', Mockery::any())->andReturnUsing(fn () => $this->allowed)->getMock());
    $this->scanClean = true;
    app()->instance(MalwareScanner::class, Mockery::mock(MalwareScanner::class)->shouldReceive('clean')->andReturnUsing(fn () => $this->scanClean)->getMock());
    app()->when([ArchiveImports::class, AssetProcessing::class])->needs(UserProvider::class)->give(fn () => Mockery::mock(UserProvider::class)->shouldReceive('retrieveById')->with('7')->andReturn($this->actor)->getMock());
    $this->uploads = app(MediaUploads::class);
    $rights = ['source' => 'Original take', 'license' => 'CC-BY-4.0', 'attribution' => 'Artist'];
    $format = pack('vvVVvv', 1, 1, 48000, 96000, 2, 16);
    $data = 'fmt '.pack('V', 16).$format.'data'.pack('V', 4).pack('v*', 0, 1000);
    $this->audio = 'RIFF'.pack('V', strlen($data) + 4).'WAVE'.$data;
    $this->source = (string) Str::uuid();
    $this->uploads->start($this->actor, new StartUpload('origin', $this->source, 'Take', strlen($this->audio), hash('sha256', $this->audio), $rights));
    $this->uploads->append($this->actor, 'origin', $this->source, 0, $this->audio);
    $this->uploads->complete($this->actor, 'origin', $this->source);
    $original = (string) Str::uuid();
    app(MediaPacks::class)->save($this->actor, new SaveMediaPack('origin', $original, 'Originals', [$this->source], $rights, 0, 'save'));
    $stream = tmpfile();
    app(PackArchives::class)->exportTo($this->actor, 'origin', $original, $stream);
    rewind($stream);
    $this->zip = stream_get_contents($stream);
    fclose($stream);
    $this->scopes = ['music.media.read', 'music.media.upload', 'music.media.packs.import', 'music.media.archives.import'];
    Sanctum::actingAs($this->actor, $this->scopes);
    Bus::fake();
    $this->base = '/api/v1/music-media/workspaces/studio';
    $this->input = (string) Str::uuid();
    $this->target = (string) Str::uuid();
    $this->task = (string) Str::uuid();
    $this->pack = (string) Str::uuid();
    $this->inputUrl = $this->base.'/archive-inputs/'.$this->input;
    $this->taskUrl = $this->base.'/archive-import-tasks/'.$this->task;
    $this->requestUrl = $this->base.'/packs/'.$this->pack.'/archive-import-tasks/'.$this->task;
    $this->declaration = ['name' => 'Reviewed.zip', 'bytes' => strlen($this->zip), 'sha256' => hash('sha256', $this->zip)];
    $this->payload = ['input_id' => $this->input, 'asset_map' => [$this->source => $this->target]];
    $this->declare = fn (array $changes = []) => $this->withHeader('Idempotency-Key', $this->input)->putJson($this->inputUrl, array_replace($this->declaration, $changes));
    $this->chunk = function (int $offset, string $bytes, ?string $checksum = null, ?string $url = null) {
        $checksum ??= hash('sha256', $bytes);

        return $this->call('PUT', ($url ?? $this->inputUrl).'/chunks/'.$offset, [], [], [], ['CONTENT_TYPE' => 'application/octet-stream',
            'HTTP_X_CHUNK_SHA256' => $checksum, 'HTTP_IDEMPOTENCY_KEY' => $this->input.':'.$offset.':'.$checksum], $bytes);
    };
    $this->stage = function () {
        ($this->declare)()->assertSuccessful();
        ($this->chunk)(0, $this->zip)->assertSuccessful();
    };
    $this->requestImport = fn (array $changes = []) => $this->withHeader('Idempotency-Key', $this->task)->putJson($this->requestUrl, array_replace($this->payload, $changes));
    $this->process = function () {
        $dispatch = DB::table('music_media_archive_import_tasks')->where('id', $this->task)->value('dispatch_id');
        (new ImportPackArchiveTask($this->task, $dispatch))->handle(app(ArchiveImports::class));
    };
});

it('resumes private archive bytes and publishes a verified pack through an owned queued import', function () {
    ($this->declare)()->assertSuccessful()->assertJsonPath('data.received_bytes', 0);
    ($this->chunk)(0, substr($this->zip, 0, 30))->assertSuccessful()->assertJsonPath('data.received_bytes', 30);
    ($this->chunk)(0, substr($this->zip, 0, 30))->assertSuccessful()->assertJsonPath('data.received_bytes', 30);
    $this->getJson($this->inputUrl)->assertSuccessful()->assertJsonPath('data.status', 'uploading');
    ($this->requestImport)()->assertUnprocessable()->assertJsonPath('errors.0.pointer', '/input_id');
    ($this->chunk)(30, substr($this->zip, 30))->assertSuccessful()->assertJsonPath('data.received_bytes', strlen($this->zip));
    ($this->requestImport)()->assertAccepted()->assertHeader('Location', $this->taskUrl)->assertJsonPath('data.kind', 'import_pack_archive');
    Bus::assertDispatched(ImportPackArchiveTask::class);
    $this->getJson($this->taskUrl)->assertSuccessful()->assertJsonPath('data.progress.unit', 'processed_assets');
    $this->getJson($this->taskUrl.'/result')->assertAccepted()->assertHeader('Retry-After', '2');
    ($this->process)();
    $result = $this->getJson($this->taskUrl.'/result')->assertSuccessful()->assertHeader('ETag', '"1"')->assertJsonPath('data.version', 1)->assertJsonPath('data.assets.0.reference.id', $this->target);
    expect($result->json('links.pack'))->toBe($this->base.'/packs/'.$this->pack);
    $this->getJson($this->inputUrl)->assertSuccessful()->assertJsonPath('data.status', 'cancelled');
    ($this->requestImport)()->assertSuccessful()->assertJsonPath('data.status', 'ready');
    expect(app(MediaUploads::class)->get($this->actor, 'studio', $this->target)->metadata['sha256'])->toBe(hash('sha256', $this->audio));
    $this->getJson($this->base.'/assets/'.$this->input)->assertNotFound();
    expect($result->getContent())->not->toContain('quarantine/', 'object_key', 'lease_nonce', 'dispatch_id');
});

it('isolates archive input owners purposes and workspaces on every byte operation', function () {
    ($this->stage)();
    $audio = $this->base.'/uploads/'.$this->input;
    $this->getJson($audio)->assertNotFound();
    ($this->chunk)(0, $this->zip, url: $audio)->assertNotFound();
    $this->withHeader('Idempotency-Key', $this->input)->deleteJson($audio)->assertNotFound();
    $this->getJson(str_replace('/studio/', '/other/', $this->inputUrl))->assertNotFound();
    Sanctum::actingAs((new Actor())->forceFill(['id' => 8]), $this->scopes);
    $this->getJson($this->inputUrl)->assertNotFound();
    ($this->chunk)(0, $this->zip)->assertNotFound();
    $this->withHeader('Idempotency-Key', $this->input)->deleteJson($this->inputUrl)->assertNotFound();
    ($this->declare)()->assertConflict();
    Sanctum::actingAs($this->actor, $this->scopes);
    $this->withHeader('Idempotency-Key', $this->input)->deleteJson($this->inputUrl)->assertSuccessful()->assertJsonPath('data.status', 'cancelled');
    $this->deleteJson($this->inputUrl)->assertSuccessful();
    ($this->chunk)(0, $this->zip)->assertConflict();
});

it('cancels queued imports and reports failed results with safe same-command retry', function () {
    ($this->stage)();
    ($this->requestImport)()->assertAccepted();
    $this->deleteJson($this->taskUrl)->assertSuccessful()->assertJsonPath('data.status', 'cancelled');
    ($this->requestImport)()->assertSuccessful()->assertJsonPath('data.status', 'cancelled');
    $this->getJson($this->taskUrl.'/result')->assertConflict();
    $this->task = (string) Str::uuid();
    $this->requestUrl = $this->base.'/packs/'.$this->pack.'/archive-import-tasks/'.$this->task;
    $this->taskUrl = $this->base.'/archive-import-tasks/'.$this->task;
    ($this->requestImport)()->assertAccepted();
    $this->allowed = false;
    expect(fn () => ($this->process)())->toThrow(AuthorizationException::class);
    $this->allowed = true;
    $this->getJson($this->taskUrl)->assertSuccessful()->assertJsonPath('data.error_code', 'archive_import_failed');
    $this->getJson($this->taskUrl.'/result')->assertConflict();
    ($this->requestImport)()->assertAccepted();
    ($this->process)();
    $this->getJson($this->taskUrl.'/result')->assertSuccessful();
});

it('polls and cooperatively cancels a running import without discarding verified recordings', function () {
    ($this->stage)();
    ($this->requestImport)()->assertAccepted();
    app(Events::class)->listen(AssetReady::class, function ($event) {
        if ($event->asset->workspaceId === 'studio') {
            ($this->requestImport)()->assertAccepted()->assertJsonPath('data.status', 'running');
            $this->getJson($this->taskUrl.'/result')->assertAccepted()->assertHeader('Retry-After', '2');
            $this->deleteJson($this->taskUrl)->assertAccepted()->assertJsonPath('data.cancel_requested', true);
        }
    });
    ($this->process)();
    $this->getJson($this->taskUrl)->assertSuccessful()->assertJsonPath('data.status', 'cancelled');
    expect($this->uploads->get($this->actor, 'studio', $this->target)->id)->toBe($this->target);
    $this->getJson($this->taskUrl.'/result')->assertConflict();
});

it('enforces every intake and import token ability and current foundation policy', function () {
    ($this->stage)();
    ($this->requestImport)()->assertAccepted();
    foreach ($this->scopes as $missing) {
        Sanctum::actingAs($this->actor, array_values(array_diff($this->scopes, [$missing])));
        ($this->requestImport)()->assertForbidden();
        $this->getJson($this->taskUrl)->assertForbidden();
        $this->getJson($this->taskUrl.'/result')->assertForbidden();
        $this->deleteJson($this->taskUrl)->assertForbidden();
        if (in_array($missing, ['music.media.upload', 'music.media.archives.import'])) {
            ($this->declare)()->assertForbidden();
            $this->getJson($this->inputUrl)->assertForbidden();
            ($this->chunk)(0, $this->zip)->assertForbidden();
            $this->withHeader('Idempotency-Key', $this->input)->deleteJson($this->inputUrl)->assertForbidden();
        }
    }
    Sanctum::actingAs($this->actor, $this->scopes);
    $this->allowed = false;
    $this->getJson($this->taskUrl)->assertForbidden();
    ($this->requestImport)()->assertForbidden();
    $this->getJson($this->taskUrl.'/result')->assertForbidden();
    $this->withHeader('Idempotency-Key', $this->task)->deleteJson($this->taskUrl)->assertForbidden();
    $this->allowed = true;
    $this->denied = 'upload';
    $this->getJson($this->inputUrl)->assertForbidden();
    ($this->declare)()->assertForbidden();
    ($this->chunk)(0, $this->zip)->assertForbidden();
    $this->withHeader('Idempotency-Key', $this->input)->deleteJson($this->inputUrl)->assertForbidden();
});

it('rejects task theft mapping conflicts and malformed bounded requests', function () {
    ($this->stage)();
    $this->flushHeaders()->putJson($this->requestUrl, $this->payload)->assertStatus(428);
    $this->withHeader('Idempotency-Key', (string) Str::uuid())->putJson($this->requestUrl, $this->payload)->assertUnprocessable();
    ($this->requestImport)(['input_id' => 'bad'])->assertUnprocessable();
    ($this->requestImport)(['asset_map' => ['bad' => $this->target]])->assertUnprocessable()->assertJsonPath('errors.0.pointer', '/asset_map');
    ($this->requestImport)(['asset_map' => null])->assertUnprocessable();
    ($this->requestImport)(['asset_map' => [$this->source => $this->input]])->assertUnprocessable()->assertJsonPath('errors.0.pointer', '/asset_map');
    ($this->requestImport)(['oversized' => str_repeat('x', 131073)])->assertStatus(413);
    ($this->requestImport)()->assertAccepted();
    ($this->requestImport)(['asset_map' => [$this->source => (string) Str::uuid()]])->assertConflict();
    $this->getJson(str_replace('/studio/', '/other/', $this->taskUrl))->assertNotFound();
    Sanctum::actingAs((new Actor())->forceFill(['id' => 8]), $this->scopes);
    $this->getJson($this->taskUrl)->assertNotFound();
    $this->getJson($this->taskUrl.'/result')->assertNotFound();
    $this->withHeader('Idempotency-Key', $this->task)->deleteJson($this->taskUrl)->assertNotFound();
    ($this->requestImport)()->assertNotFound();
});

it('validates archive byte limits checksums chunk bounds and immutable declaration identity', function () {
    $this->flushHeaders()->putJson($this->inputUrl, $this->declaration)->assertStatus(428);
    ($this->declare)(['bytes' => 1090519041])->assertUnprocessable();
    ($this->declare)(['sha256' => hash('sha256', $this->zip).'x'])->assertUnprocessable();
    ($this->declare)()->assertSuccessful();
    ($this->declare)(['name' => 'Changed'])->assertConflict();
    ($this->chunk)(0, $this->zip, str_repeat('0', 64))->assertUnprocessable();
    ($this->chunk)(0, $this->zip.'bad', hash('sha256', $this->zip.'bad')."\n")->assertUnprocessable();
    ($this->chunk)(0, '')->assertUnprocessable();
    ($this->chunk)(0, str_repeat('x', 4194305))->assertStatus(413);
    ($this->chunk)(1090519041, 'x')->assertUnprocessable();
    ($this->chunk)(0, $this->zip)->assertSuccessful();
    ($this->chunk)(0, str_repeat('x', strlen($this->zip)))->assertConflict();
    $this->getJson($this->inputUrl)->assertSuccessful()->assertJsonPath('data.received_bytes', strlen($this->zip));
    $this->withHeader('Idempotency-Key', $this->task)->putJson($this->base.'/verification-tasks/'.$this->task, ['upload_id' => $this->input])->assertAccepted();
    expect(fn () => app(AssetProcessing::class)->process($this->task))->toThrow(UploadConflict::class);
});

it('supports configured archive sizes beyond audio limits and validates eleven digit byte offsets', function () {
    config()->set('music-media.archive_max_audio_bytes', 17179869184);
    ($this->declare)(['bytes' => 4294967340])->assertSuccessful()->assertJsonPath('data.total_bytes', 4294967340);
    ($this->chunk)(4294967296, 'x')->assertConflict();
    ($this->chunk)(17196646400, 'x')->assertConflict();
    ($this->chunk)(17196646401, 'x')->assertUnprocessable();
    $this->getJson($this->inputUrl)->assertSuccessful()->assertJsonPath('data.received_bytes', 0);
});
