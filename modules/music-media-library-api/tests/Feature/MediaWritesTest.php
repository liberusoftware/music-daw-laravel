<?php

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Liberu\Foundation\Files\Contracts\MalwareScanner;
use Liberu\Foundation\ImportExport\Contracts\TransferAuthorizer;
use Liberu\Music\MediaLibrary\Commands\RetainAssets;
use Liberu\Music\MediaLibrary\Exceptions\InvalidAudio;
use Liberu\Music\MediaLibrary\MusicMediaLibraryServiceProvider;
use Liberu\Music\MediaLibrary\Services\AssetProcessing;
use Liberu\Music\MediaLibrary\Services\MediaRetention;
use Liberu\Music\MediaLibrary\Services\TrashRecovery;
use Liberu\Music\MediaLibraryApi\Http\Middleware\ApiBoundary;
use Liberu\Music\MediaLibraryApi\Tests\Fixtures\Actor;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

beforeEach(function () {
    $this->app->register(MusicMediaLibraryServiceProvider::class);
    $path = dirname((new ReflectionClass(MusicMediaLibraryServiceProvider::class))->getFileName(), 2).'/database/migrations';
    $this->artisan('migrate', ['--path' => $path, '--realpath' => true])->assertSuccessful();
    config()->set('music-media.disk', 'api-media-writes');
    $this->disk = Storage::fake('api-media-writes');
    $this->actor = (new Actor())->forceFill(['id' => 7]);
    $this->denied = null;
    foreach (['upload', 'read', 'list', 'annotate', 'restore', 'retain'] as $operation) {
        app(Gate::class)->define('music.media.'.$operation, fn ($actor, $workspace) => $actor->id === 7 && in_array($workspace, ['studio', 'other']) && $this->denied !== $operation);
    }
    $this->scanClean = true;
    app()->instance(MalwareScanner::class, Mockery::mock(MalwareScanner::class)->shouldReceive('clean')->andReturnUsing(fn () => $this->scanClean)->getMock());
    $users = Mockery::mock(UserProvider::class)->shouldReceive('retrieveById')->with('7')->andReturn($this->actor)->getMock();
    app()->when(AssetProcessing::class)->needs(UserProvider::class)->give(fn () => $users);
    Bus::fake();
    Sanctum::actingAs($this->actor, ['music.media.read', 'music.media.edit', 'music.media.upload', 'music.media.restore']);
    $format = pack('vvVVvv', 1, 1, 48000, 96000, 2, 16);
    $chunks = 'fmt '.pack('V', 16).$format.'data'.pack('V', 4).pack('v*', 0, 16384);
    $this->bytes = 'RIFF'.pack('V', strlen($chunks) + 4).'WAVE'.$chunks;
    $this->base = '/api/v1/music-media/workspaces/studio';
    $this->id = (string) Str::uuid();
    $this->declaration = ['name' => 'Take', 'bytes' => strlen($this->bytes), 'sha256' => hash('sha256', $this->bytes), 'provenance' => ['license' => 'original']];
    $this->declare = fn (?string $id = null) => $this->withHeader('Idempotency-Key', $id ?? $this->id)->putJson($this->base.'/uploads/'.($id ?? $this->id), $this->declaration);
    $this->chunk = function (string $id, int $offset, string $bytes, ?string $checksum = null) {
        $checksum ??= hash('sha256', $bytes);

        return $this->call('PUT', $this->base.'/uploads/'.$id.'/chunks/'.$offset, [], [], [], ['CONTENT_TYPE' => 'application/octet-stream',
            'HTTP_X_CHUNK_SHA256' => $checksum, 'HTTP_IDEMPOTENCY_KEY' => $id.':'.$offset.':'.$checksum], $bytes);
    };
    $this->verify = function (string $id, ?string $task = null): string {
        $task ??= (string) Str::uuid();
        $this->withHeader('Idempotency-Key', $task)->putJson($this->base.'/verification-tasks/'.$task, ['upload_id' => $id])->assertAccepted();
        app(AssetProcessing::class)->process($task);

        return $task;
    };
    $this->publish = function (): string {
        $id = (string) Str::uuid();
        ($this->declare)($id)->assertSuccessful();
        ($this->chunk)($id, 0, $this->bytes)->assertSuccessful();
        ($this->verify)($id);

        return $id;
    };
});

it('resumes verified chunks without making incomplete audio available to readers', function () {
    ($this->declare)()->assertSuccessful()->assertJsonPath('data.received_bytes', 0);
    $this->getJson($this->base.'/assets/'.$this->id)->assertNotFound();
    ($this->chunk)($this->id, 0, substr($this->bytes, 0, 24))->assertSuccessful()->assertJsonPath('data.received_bytes', 24);
    ($this->chunk)($this->id, 0, substr($this->bytes, 0, 24))->assertSuccessful()->assertJsonPath('data.received_bytes', 24);
    $this->getJson($this->base.'/uploads/'.$this->id)->assertSuccessful()->assertJsonPath('data.received_bytes', 24);
    ($this->chunk)($this->id, 24, substr($this->bytes, 24))->assertSuccessful()->assertJsonPath('data.received_bytes', 48);
    $task = (string) Str::uuid();
    $this->withHeader('Idempotency-Key', $task)->putJson($this->base.'/verification-tasks/'.$task, ['upload_id' => $this->id])->assertAccepted()->assertHeader('Location', $this->base.'/asset-tasks/'.$task);
    $this->getJson($this->base.'/asset-tasks/'.$task)->assertSuccessful()->assertJsonPath('data.kind', 'verify_upload');
    $this->getJson($this->base.'/asset-tasks/'.$task.'/result')->assertAccepted()->assertHeader('Retry-After', '2');
    app(AssetProcessing::class)->process($task);
    $this->getJson($this->base.'/asset-tasks/'.$task.'/result')->assertSuccessful()->assertJsonPath('data.id', $this->id)->assertJsonPath('data.frames', 2);
    $this->getJson($this->base.'/assets/'.$this->id)->assertSuccessful()->assertHeader('ETag', '"0"');
    $this->putJson($this->base.'/verification-tasks/'.$task, ['upload_id' => $this->id])->assertSuccessful()->assertJsonPath('data.status', 'ready');
});

it('edits metadata conditionally and distinguishes original replay key reuse and stale versions', function () {
    $id = ($this->publish)();
    $url = $this->base.'/assets/'.$id.'/metadata';
    $payload = ['name' => 'Kick', 'tags' => ['Drums', ' DRUMS ']];
    $this->withHeaders(['Idempotency-Key' => 'edit-1', 'If-Match' => '"0"'])->putJson($url, $payload)->assertSuccessful()->assertHeader('ETag', '"1"')->assertJsonPath('data.tags', ['drums']);
    $this->putJson($url, $payload)->assertSuccessful()->assertJsonPath('data.version', 1);
    $this->putJson($url, ['name' => 'Different', 'tags' => []])->assertConflict()->assertJsonPath('type', 'https://docs.liberusoftware.com/problems/music-media-metadata-command-conflict');
    $this->withHeader('Idempotency-Key', 'edit-2')->putJson($url, $payload)->assertStatus(412);
    $this->withHeader('If-Match', '"1"')->putJson($url, ['name' => 'Snare', 'tags' => []])->assertSuccessful()->assertHeader('ETag', '"2"');
    $this->withHeaders(['Idempotency-Key' => 'edit-1', 'If-Match' => '"0"'])->putJson($url, $payload)->assertSuccessful()->assertJsonPath('data.name', 'Kick');
    $this->getJson($this->base.'/assets/'.$id)->assertSuccessful()->assertJsonPath('data.name', 'Snare')->assertHeader('ETag', '"2"');
});

it('rejects missing weak wildcard oversized and overflowing metadata preconditions', function () {
    $id = ($this->publish)();
    $url = $this->base.'/assets/'.$id.'/metadata';
    $payload = ['name' => 'Edit', 'tags' => []];
    $this->withHeader('Idempotency-Key', 'key')->putJson($url, $payload)->assertStatus(428);
    foreach (['*', 'W/"0"', '"00"', '"0", "1"', '"9223372036854775808"', '"'.PHP_INT_MAX.'"'] as $etag) {
        $this->withHeader('If-Match', $etag)->putJson($url, $payload)->assertUnprocessable();
    }
    $this->withHeaders(['If-Match' => '"0"', 'Idempotency-Key' => str_repeat('a', 129)])->putJson($url, $payload)->assertUnprocessable();
    $this->withHeader('Idempotency-Key', 'good')->putJson($url, ['name' => '', 'tags' => 'bad'])->assertUnprocessable();
});

it('enforces declaration identity quota and independent upload cancellation', function () {
    $this->putJson($this->base.'/uploads/'.$this->id, $this->declaration)->assertStatus(428);
    ($this->declare)()->assertSuccessful();
    ($this->declare)()->assertSuccessful();
    $this->declaration['name'] = 'Changed';
    ($this->declare)()->assertConflict();
    config()->set('music-media.max_reserved_bytes', 96);
    $other = (string) Str::uuid();
    ($this->declare)($other)->assertTooManyRequests()->assertJsonPath('type', 'https://docs.liberusoftware.com/problems/music-media-upload-budget-exceeded');
    $this->withHeader('Idempotency-Key', $this->id)->deleteJson($this->base.'/uploads/'.$this->id)->assertSuccessful()->assertJsonPath('data.status', 'cancelled');
    $this->deleteJson($this->base.'/uploads/'.$this->id)->assertSuccessful();
    ($this->chunk)($this->id, 0, $this->bytes)->assertConflict();
});

it('rejects altered gaps empty oversized and incorrectly typed chunk transfers', function () {
    ($this->declare)()->assertSuccessful();
    ($this->chunk)($this->id, 0, $this->bytes, str_repeat('a', 64))->assertUnprocessable();
    ($this->chunk)($this->id, 1, $this->bytes)->assertConflict();
    ($this->chunk)($this->id, 0, '')->assertUnprocessable();
    $url = $this->base.'/uploads/'.$this->id.'/chunks/0';
    $this->putJson($url, [])->assertStatus(415);
    $this->call('PUT', $url, [], [], [], ['CONTENT_TYPE' => 'application/octet-stream'], $this->bytes)->assertUnprocessable();
    ($this->chunk)($this->id, 0, str_repeat('x', 4194305))->assertStatus(413);
    ($this->chunk)($this->id, 0, $this->bytes)->assertSuccessful();
    ($this->chunk)($this->id, 0, substr_replace($this->bytes, pack('v', 1), -2))->assertConflict();
});

it('cancels task publication safely without discarding resumable source bytes', function () {
    ($this->declare)()->assertSuccessful();
    ($this->chunk)($this->id, 0, $this->bytes)->assertSuccessful();
    $task = (string) Str::uuid();
    $this->withHeader('Idempotency-Key', $task)->putJson($this->base.'/verification-tasks/'.$task, ['upload_id' => $this->id])->assertAccepted();
    $url = $this->base.'/asset-tasks/'.$task;
    $this->deleteJson($url)->assertSuccessful()->assertJsonPath('data.status', 'cancelled');
    $this->deleteJson($url)->assertSuccessful();
    $this->getJson($url.'/result')->assertConflict();
    $this->getJson($this->base.'/uploads/'.$this->id)->assertSuccessful()->assertJsonPath('data.status', 'uploading');
});

it('restores missing retained audio through a queued original-checksum replacement', function () {
    $target = ($this->publish)();
    app(MediaRetention::class)->retain($this->actor, new RetainAssets('studio', 'revision:1', [$target]));
    $this->disk->delete('music/assets/'.hash('sha256', 'studio').'/'.hash('sha256', $this->bytes));
    ($this->declare)()->assertSuccessful();
    ($this->chunk)($this->id, 0, $this->bytes)->assertSuccessful();
    $task = (string) Str::uuid();
    $this->withHeader('Idempotency-Key', $task)->putJson($this->base.'/assets/'.$target.'/restoration-tasks/'.$task, ['replacement_upload_id' => $this->id])->assertAccepted()->assertJsonPath('data.kind', 'restore_asset');
    app(AssetProcessing::class)->process($task);
    $this->getJson($this->base.'/asset-tasks/'.$task.'/result')->assertSuccessful()->assertJsonPath('data.id', $target);
    $this->getJson($this->base.'/assets/'.$target)->assertSuccessful()->assertJsonPath('data.available', true);
    expect(DB::table('music_media_retention')->where('asset_id', $target)->count())->toBe(1);
});

it('enforces scopes task ownership revocation and missing-workspace outcomes', function () {
    ($this->declare)()->assertSuccessful();
    ($this->chunk)($this->id, 0, $this->bytes)->assertSuccessful();
    $task = (string) Str::uuid();
    $this->withHeader('Idempotency-Key', $task)->putJson($this->base.'/verification-tasks/'.$task, ['upload_id' => $this->id])->assertAccepted();
    $this->getJson('/api/v1/music-media/workspaces/other/asset-tasks/'.$task)->assertNotFound();
    $this->getJson('/api/v1/music-media/workspaces/other/uploads/'.$this->id)->assertNotFound();
    $this->denied = 'upload';
    $this->getJson($this->base.'/asset-tasks/'.$task)->assertForbidden();
    $this->denied = null;
    Sanctum::actingAs($this->actor, ['music.media.read']);
    $this->getJson($this->base.'/asset-tasks/'.$task)->assertForbidden();
    $this->deleteJson($this->base.'/asset-tasks/'.$task)->assertForbidden();
    ($this->declare)()->assertForbidden();
});

it('never publishes scanner-rejected bytes or hides terminal task failure', function () {
    ($this->declare)()->assertSuccessful();
    ($this->chunk)($this->id, 0, $this->bytes)->assertSuccessful();
    $task = (string) Str::uuid();
    $this->withHeader('Idempotency-Key', $task)->putJson($this->base.'/verification-tasks/'.$task, ['upload_id' => $this->id])->assertAccepted();
    $this->scanClean = false;
    expect(fn () => app(AssetProcessing::class)->process($task))->toThrow(InvalidAudio::class);
    app(AssetProcessing::class)->failed($task);
    $this->getJson($this->base.'/asset-tasks/'.$task)->assertSuccessful()->assertJsonPath('data.status', 'failed')->assertJsonPath('data.error_code', 'asset_processing_failed');
    $this->getJson($this->base.'/assets/'.$this->id)->assertNotFound();
});

it('requires the exact declaration and chunk retry identity before writing domain state', function () {
    $this->withHeader('Idempotency-Key', (string) Str::uuid())->putJson($this->base.'/uploads/'.$this->id, $this->declaration)->assertUnprocessable();
    $this->getJson($this->base.'/uploads/'.$this->id)->assertNotFound();
    ($this->declare)()->assertSuccessful();
    $this->call('PUT', $this->base.'/uploads/'.$this->id.'/chunks/0', [], [], [], ['CONTENT_TYPE' => 'application/octet-stream',
        'HTTP_X_CHUNK_SHA256' => hash('sha256', $this->bytes), 'HTTP_IDEMPOTENCY_KEY' => 'different'], $this->bytes)->assertUnprocessable();
    $this->getJson($this->base.'/uploads/'.$this->id)->assertSuccessful()->assertJsonPath('data.received_bytes', 0);
});

it('requires both edit scope and current annotation permission', function () {
    $id = ($this->publish)();
    $url = $this->base.'/assets/'.$id.'/metadata';
    $this->denied = 'annotate';
    $this->withHeaders(['Idempotency-Key' => 'edit', 'If-Match' => '"0"'])->putJson($url, ['name' => 'Denied', 'tags' => []])->assertForbidden();
    $this->denied = null;
    Sanctum::actingAs($this->actor, ['music.media.read']);
    $this->putJson($url, ['name' => 'Denied', 'tags' => []])->assertForbidden();
    $this->getJson($this->base.'/assets/'.$id)->assertSuccessful()->assertHeader('ETag', '"0"');
});

it('reports public input pointers for incomplete intake and invalid self-restoration', function () {
    ($this->declare)()->assertSuccessful();
    $task = (string) Str::uuid();
    $this->withHeader('Idempotency-Key', $task)->putJson($this->base.'/verification-tasks/'.$task, ['upload_id' => $this->id])->assertUnprocessable()->assertJsonPath('errors.0.pointer', '/upload_id');
    $this->getJson($this->base.'/asset-tasks/'.$task)->assertNotFound();
    $target = ($this->publish)();
    $restore = (string) Str::uuid();
    $this->withHeader('Idempotency-Key', $restore)->putJson($this->base.'/assets/'.$target.'/restoration-tasks/'.$restore, ['replacement_upload_id' => $target])->assertUnprocessable()->assertJsonPath('errors.0.pointer', '/replacement_upload_id');
});

it('rejects an oversized declared body before reading its input stream', function () {
    $request = new class([], [], [], [], [], ['REQUEST_METHOD' => 'PUT', 'CONTENT_LENGTH' => '4194305', 'CONTENT_TYPE' => 'application/octet-stream'], 'not read') extends Request
    {
        public function getContent(bool $asResource = false)
        {
            throw new RuntimeException('The rejected input stream must not be opened.');
        }
    };
    $request->setRouteResolver(fn () => (new Route('PUT', '/chunk', []))->name('music.media.api.append'));
    try {
        (new ApiBoundary())->handle($request, fn () => response('should not run'));
        $this->fail('The oversized body was accepted.');
    } catch (HttpExceptionInterface $exception) {
        expect($exception->getStatusCode())->toBe(413);
    }
});

it('issues short-lived actor-scoped download URLs and streams verified private WAVE bytes', function () {
    $id = ($this->publish)();
    $endpoint = $this->base.'/assets/'.$id.'/download-url';
    $this->getJson($endpoint)->assertForbidden();
    Sanctum::actingAs($this->actor, ['music.media.read', 'music.media.download']);
    app(Gate::class)->define('music.media.download', fn () => true);
    $this->getJson($endpoint)->assertForbidden();
    $allowed = true;
    $policy = Mockery::mock(TransferAuthorizer::class);
    $policy->shouldReceive('allowed')->with('7', 'music-media-asset', 'export')->andReturnUsing(function () use (&$allowed) {
        return $allowed;
    });
    app()->instance(TransferAuthorizer::class, $policy);
    $grant = $this->getJson($endpoint)->assertSuccessful()->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('data.sha256', hash('sha256', $this->bytes))->assertJsonPath('data.bytes', strlen($this->bytes));
    $url = $grant->json('data.url');
    expect(Carbon::parse($grant->json('data.expires_at'))->timestamp - now()->timestamp)->toBe(60);
    expect($url)->not->toContain('music/assets', 'quarantine');
    $response = $this->get($url)->assertSuccessful()->assertHeader('Content-Type', 'audio/wav')
        ->assertHeader('ETag', '"'.hash('sha256', $this->bytes).'"')->assertHeader('Content-Length', (string) strlen($this->bytes))
        ->assertHeader('Accept-Ranges', 'none')->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($response->streamedContent())->toBe($this->bytes);
    $allowed = false;
    $this->get($url)->assertForbidden();
    $allowed = true;
    $this->denied = 'read';
    $this->get($url)->assertForbidden();
    $this->denied = null;
    app(Gate::class)->define('music.media.download', fn () => false);
    $this->get($url)->assertForbidden();
});

it('rejects expired tampered actor-mismatched and stale-checksum download links', function () {
    $id = ($this->publish)();
    Sanctum::actingAs($this->actor, ['music.media.read', 'music.media.download']);
    app(Gate::class)->define('music.media.download', fn () => true);
    app()->instance(TransferAuthorizer::class,
        Mockery::mock(TransferAuthorizer::class)->shouldReceive('allowed')->andReturn(true)->getMock());
    $url = $this->getJson($this->base.'/assets/'.$id.'/download-url')->assertSuccessful()->json('data.url');
    $this->get($url.'&extra=value')->assertForbidden();
    $this->get($url, ['Range' => 'bytes=0-10'])->assertStatus(416)->assertHeader('Accept-Ranges', 'none');
    $this->get(URL::temporarySignedRoute('music.media.api.download', now()->addMinute(),
        ['workspace' => 'studio', 'asset' => $id, 'actor' => '8', 'sha256' => hash('sha256', $this->bytes)]))->assertForbidden();
    $this->get(URL::temporarySignedRoute('music.media.api.download', now()->addMinute(),
        ['workspace' => 'studio', 'asset' => $id, 'actor' => '7', 'sha256' => str_repeat('a', 64)]))->assertForbidden();
    $this->travel(61)->seconds();
    $this->get($url)->assertForbidden();
    $this->travelBack();
});

it('fails closed for changed missing unverified and cross-workspace downloadable media', function () {
    $id = ($this->publish)();
    Sanctum::actingAs($this->actor, ['music.media.read', 'music.media.download']);
    app(Gate::class)->define('music.media.download', fn () => true);
    app()->instance(TransferAuthorizer::class,
        Mockery::mock(TransferAuthorizer::class)->shouldReceive('allowed')->andReturn(true)->getMock());
    $endpoint = $this->base.'/assets/'.$id.'/download-url';
    $url = $this->getJson($endpoint)->assertSuccessful()->json('data.url');
    $key = DB::table('music_media_uploads')->where('id', $id)->value('blob_key');
    $this->disk->put($key, str_repeat('x', strlen($this->bytes)));
    $this->get($url)->assertUnprocessable()->assertJsonPath('status', 422);
    $this->disk->delete($key);
    $this->get($url)->assertUnprocessable();
    $this->getJson($endpoint)->assertUnprocessable();
    $this->getJson(str_replace('/studio/', '/other/', $endpoint))->assertNotFound();
    DB::table('music_media_uploads')->where('id', $id)->update(['status' => 'uploading']);
    $this->getJson($endpoint)->assertNotFound();
});

describe('reviewed pack manifests', function () {
    beforeEach(function () {
        $this->rights = ['source' => 'Studio originals', 'license' => 'CC-BY-4.0', 'attribution' => 'Artist'];
        $this->declaration['provenance'] = $this->rights;
        foreach (['pack_read', 'pack_write', 'pack_export', 'pack_import', 'distribute'] as $operation) {
            app(Gate::class)->define('music.media.'.$operation, fn ($actor, $workspace) => $actor->id === 7 && in_array($workspace, ['studio', 'other']) && $this->denied !== $operation);
        }
        app(Gate::class)->define('music.media.pack_distribute', fn () => $this->denied !== 'pack_distribute');
        $this->transferAllowed = true;
        app()->instance(TransferAuthorizer::class,
            Mockery::mock(TransferAuthorizer::class)->shouldReceive('allowed')->with('7', 'music-media-pack', Mockery::any())->andReturnUsing(fn () => $this->transferAllowed)->getMock());
        Sanctum::actingAs($this->actor, ['music.media.read', 'music.media.upload', 'music.media.packs.read', 'music.media.packs.write', 'music.media.packs.export', 'music.media.packs.import']);
        $this->asset = ($this->publish)();
        $this->pack = (string) Str::uuid();
        $this->packUrl = $this->base.'/packs/'.$this->pack;
        $this->packBody = ['name' => 'Studio pack', 'asset_ids' => [$this->asset], 'rights' => $this->rights];
        $this->savePack = fn (int $version = 0, string $key = 'create') => $this->withHeaders(['If-Match' => '"'.$version.'"', 'Idempotency-Key' => $key])->putJson($this->packUrl, $this->packBody);
    });

    it('saves reads and paginates immutable pack revisions with replay and conflict preconditions', function () {
        $this->flushHeaders()->putJson($this->packUrl, $this->packBody)->assertStatus(428);
        ($this->savePack)()->assertSuccessful()->assertHeader('ETag', '"1"')->assertJsonPath('data.rights.license', 'CC-BY-4.0');
        ($this->savePack)()->assertSuccessful()->assertHeader('ETag', '"1"');
        $this->packBody['name'] = 'Updated pack';
        ($this->savePack)(1, 'update')->assertSuccessful()->assertHeader('ETag', '"2"');
        ($this->savePack)(0, 'stale')->assertStatus(412);
        ($this->savePack)(0, 'create')->assertConflict();
        $this->getJson($this->packUrl)->assertSuccessful()->assertJsonPath('data.name', 'Updated pack');
        $this->getJson($this->packUrl.'?version=1')->assertSuccessful()->assertJsonPath('data.name', 'Studio pack');
        $first = $this->getJson($this->packUrl.'/history?page[limit]=1')->assertSuccessful()->assertJsonPath('meta.next_cursor', '2');
        $second = $this->getJson($first->json('links.next'))->assertSuccessful()->assertJsonPath('data.0.version', 1);
        $this->getJson($second->json('links.next'))->assertSuccessful()->assertJsonCount(0, 'data')->assertJsonPath('meta.next_cursor', null);
        $this->getJson($this->packUrl.'/history')->assertSuccessful()->assertJsonCount(2, 'data');
        expect(app(MediaRetention::class)->isProtected($this->actor, 'studio', $this->asset))->toBeTrue();
        $this->getJson(str_replace('/studio/', '/other/', $this->packUrl))->assertNotFound();
        $this->denied = 'pack_read';
        $this->getJson($this->packUrl)->assertForbidden();
        $this->getJson($this->packUrl.'/history')->assertForbidden();
    });

    it('exports previews and imports checksummed rights manifests with exact verified mappings', function () {
        ($this->savePack)()->assertSuccessful();
        $manifest = $this->getJson($this->packUrl.'/export?version=1')->assertSuccessful()->json('data');
        expect($manifest['checksum'])->toBe(hash('sha256', json_encode($manifest['document'], JSON_THROW_ON_ERROR)))
            ->and(json_encode($manifest))->not->toContain('blob_key', 'quarantine', 'music/assets/');
        $destination = $this->base.'/packs/'.Str::uuid();
        $body = ['manifest' => $manifest, 'asset_map' => [$this->asset => $this->asset]];
        $this->postJson($destination.'/import-preview', $body)->assertSuccessful()->assertJsonPath('data.pack_id', $this->pack);
        $this->getJson($destination)->assertNotFound();
        $this->withHeaders(['If-Match' => '"1"', 'Idempotency-Key' => 'import'])->putJson($destination.'/import', $body)->assertUnprocessable();
        $this->withHeaders(['If-Match' => '"0"', 'Idempotency-Key' => 'import'])->putJson($destination.'/import', $body)->assertSuccessful()->assertHeader('ETag', '"1"');
        $this->putJson($destination.'/import', $body)->assertSuccessful();
        $this->getJson($destination)->assertSuccessful()->assertJsonPath('data.assets.0.reference.id', $this->asset);
        $this->transferAllowed = false;
        $this->getJson($this->packUrl.'/export')->assertForbidden();
        $this->postJson($destination.'/import-preview', $body)->assertForbidden();
        $this->putJson($destination.'/import', $body)->assertForbidden();
        $this->transferAllowed = true;
        foreach (['distribute', 'pack_distribute'] as $denied) {
            $this->denied = $denied;
            $this->getJson($this->packUrl.'/export')->assertForbidden();
        }
    });

    it('rejects malformed mappings provenance checksum drift and unauthorized pack commands', function () {
        ($this->savePack)()->assertSuccessful();
        $manifest = $this->getJson($this->packUrl.'/export')->assertSuccessful()->json('data');
        $destination = $this->base.'/packs/'.Str::uuid();
        $body = ['manifest' => $manifest, 'asset_map' => [$this->asset => $this->asset]];
        $bad = $body;
        $bad['manifest']['checksum'] = str_repeat('0', 64);
        $this->postJson($destination.'/import-preview', $bad)->assertUnprocessable();
        $bad = $body;
        $bad['asset_map'] = [(string) Str::uuid() => $this->asset];
        $this->postJson($destination.'/import-preview', $bad)->assertUnprocessable()->assertJsonPath('errors.0.pointer', '/asset_map');
        $other = ($this->publish)();
        DB::table('music_media_uploads')->where('id', $other)->update(['provenance' => json_encode(['license' => 'different'])]);
        $bad = $body;
        $bad['asset_map'][$this->asset] = $other;
        $this->withHeaders(['If-Match' => '"0"', 'Idempotency-Key' => 'import'])->putJson($destination.'/import', $bad)->assertUnprocessable();
        $this->getJson($destination)->assertNotFound();
        $this->denied = 'read';
        $this->getJson($this->packUrl)->assertForbidden();
        $this->denied = 'pack_write';
        ($this->savePack)(1, 'denied')->assertForbidden();
        $this->denied = null;
        Sanctum::actingAs($this->actor, ['music.media.read']);
        foreach ([$this->packUrl, $this->packUrl.'/history', $this->packUrl.'/export'] as $url) {
            $this->getJson($url)->assertForbidden();
        }
        ($this->savePack)(1, 'scope-denied')->assertForbidden();
        $this->postJson($destination.'/import-preview', $body)->assertForbidden();
        $this->putJson($destination.'/import', $body)->assertForbidden();
    });

    it('validates pack fields versions pagination and bounded JSON transport', function () {
        foreach (['0', '-1', '1.5', '01', '9223372036854775808'] as $version) {
            $this->getJson($this->packUrl.'?version='.$version)->assertUnprocessable();
            $this->getJson($this->packUrl.'/history?page[before]='.$version)->assertUnprocessable();
        }
        $this->getJson($this->packUrl.'/history?page[limit]=101')->assertUnprocessable();
        $bad = $this->packBody;
        $bad['asset_ids'][] = $this->asset;
        $this->withHeaders(['If-Match' => '"0"', 'Idempotency-Key' => 'invalid'])->putJson($this->packUrl, $bad)->assertUnprocessable();
        $bad = $this->packBody;
        unset($bad['rights']['attribution']);
        $this->putJson($this->packUrl, $bad)->assertUnprocessable();
        $this->postJson($this->packUrl.'/import-preview', ['manifest' => [], 'asset_map' => []])->assertUnprocessable();
        $this->putJson($this->packUrl, $this->packBody + ['oversized' => str_repeat('x', 65537)])->assertStatus(413);
        $this->withHeader('Content-Length', '0')->postJson($this->packUrl.'/import-preview', ['oversized' => str_repeat('x', 8388609)])->assertStatus(413);
    });
});

describe('actor-owned offline pins', function () {
    beforeEach(function () {
        app(Gate::class)->define('music.media.pin', fn ($actor, $workspace) => in_array($workspace, ['studio', 'other']) && $this->denied !== 'pin');
        Sanctum::actingAs($this->actor, ['music.media.read', 'music.media.upload', 'music.media.pins.read', 'music.media.pins.write']);
        $this->asset = ($this->publish)();
        $this->pinUrl = $this->base.'/offline-pins/'.Str::uuid();
        $this->savePin = fn (array $ids, int $version = 0, string $key = 'create', ?string $expires = null) => $this->withHeaders(['If-Match' => '"'.$version.'"', 'Idempotency-Key' => $key])->putJson($this->pinUrl, ['asset_ids' => $ids, 'expires_at' => $expires]);
    });

    it('creates renews releases and replays pin receipts without reverting current retention', function () {
        ($this->savePin)([$this->asset])->assertSuccessful()->assertHeader('ETag', '"1"');
        expect(app(MediaRetention::class)->isProtected($this->actor, 'studio', $this->asset))->toBeTrue();
        ($this->savePin)([], 1, 'release')->assertSuccessful()->assertHeader('ETag', '"2"');
        expect(app(MediaRetention::class)->isProtected($this->actor, 'studio', $this->asset))->toBeFalse();
        ($this->savePin)([$this->asset])->assertSuccessful()->assertHeader('ETag', '"1"');
        $this->getJson($this->pinUrl)->assertSuccessful()->assertHeader('ETag', '"2"')->assertJsonCount(0, 'data.asset_ids');
        expect(app(MediaRetention::class)->isProtected($this->actor, 'studio', $this->asset))->toBeFalse();
        ($this->savePin)([$this->asset], 0, 'stale')->assertStatus(412);
        ($this->savePin)([], 0, 'create')->assertConflict();
        $expires = now()->addMinute()->format('Y-m-d\TH:i:s\Z');
        ($this->savePin)([$this->asset], 2, 'lease', $expires)->assertSuccessful()->assertJsonPath('data.expires_at', $expires);
        $this->travel(61)->seconds();
        expect(app(MediaRetention::class)->isProtected($this->actor, 'studio', $this->asset))->toBeFalse();
        ($this->savePin)([$this->asset], 2, 'lease', $expires)->assertSuccessful()->assertHeader('ETag', '"3"');
        expect(app(MediaRetention::class)->isProtected($this->actor, 'studio', $this->asset))->toBeFalse();
        ($this->savePin)([$this->asset], 3, 'new-expired', $expires)->assertUnprocessable()->assertJsonPath('errors.0.pointer', '/expires_at');
        ($this->savePin)([$this->asset], 3, 'renew', now()->addHour()->format('Y-m-d\TH:i:s\Z'))->assertSuccessful()->assertHeader('ETag', '"4"');
        expect(app(MediaRetention::class)->isProtected($this->actor, 'studio', $this->asset))->toBeTrue();
        $this->travelBack();
    });

    it('enforces ownership workspace token scopes and live pin asset and retention policies', function () {
        ($this->savePin)([$this->asset])->assertSuccessful();
        $this->getJson(str_replace('/studio/', '/other/', $this->pinUrl))->assertNotFound();
        Sanctum::actingAs((new Actor())->forceFill(['id' => 8]), ['music.media.read', 'music.media.pins.read', 'music.media.pins.write']);
        $this->getJson($this->pinUrl)->assertNotFound();
        ($this->savePin)([], 1, 'foreign')->assertNotFound();
        Sanctum::actingAs($this->actor, ['music.media.read']);
        $this->getJson($this->pinUrl)->assertForbidden();
        ($this->savePin)([], 1, 'scope')->assertForbidden();
        Sanctum::actingAs($this->actor, ['music.media.read', 'music.media.pins.read', 'music.media.pins.write']);
        $this->denied = 'pin';
        $this->getJson($this->pinUrl)->assertForbidden();
        ($this->savePin)([], 1, 'pin-denied')->assertForbidden();
        $this->denied = 'read';
        $this->getJson($this->pinUrl)->assertForbidden();
        ($this->savePin)([], 1, 'read-denied')->assertForbidden();
        $this->denied = 'retain';
        ($this->savePin)([], 1, 'retain-denied')->assertForbidden();
        ($this->savePin)([$this->asset])->assertForbidden();
    });

    it('rejects missing unverified malformed and oversized pin writes without partial state', function () {
        $this->flushHeaders()->putJson($this->pinUrl, ['asset_ids' => []])->assertStatus(428);
        ($this->savePin)([(string) Str::uuid()])->assertNotFound();
        $this->getJson($this->pinUrl)->assertNotFound();
        ($this->savePin)([$this->asset, $this->asset])->assertUnprocessable();
        ($this->savePin)([$this->asset], 0, 'bad-date', '2026-11-05T10:00:00+01:00')->assertUnprocessable();
        ($this->savePin)([$this->asset], 0, 'past', now()->subMinute()->format('Y-m-d\TH:i:s\Z'))->assertUnprocessable();
        $this->putJson($this->pinUrl, ['asset_ids' => [], 'oversized' => str_repeat('x', 524289)])->assertStatus(413);
        $this->getJson($this->pinUrl)->assertNotFound();
        DB::table('music_media_uploads')->where('id', $this->asset)->update(['status' => 'uploading']);
        ($this->savePin)([$this->asset])->assertNotFound();
    });
});

describe('reference-safe trash and queued recovery', function () {
    beforeEach(function () {
        foreach (['trash', 'trash_list'] as $operation) {
            app(Gate::class)->define('music.media.'.$operation, fn ($actor, $workspace) => $actor->id === 7 && in_array($workspace, ['studio', 'other']) && $this->denied !== $operation);
        }
        app()->when(TrashRecovery::class)->needs(UserProvider::class)->give(fn () => Mockery::mock(UserProvider::class)->shouldReceive('retrieveById')->with('7')->andReturn($this->actor)->getMock());
        Sanctum::actingAs($this->actor, ['music.media.read', 'music.media.upload', 'music.media.restore', 'music.media.trash.read', 'music.media.trash.write']);
        $this->asset = ($this->publish)();
        $this->trash = function (array $ids, ?string $key = null, int $grace = 24) {
            $key ??= (string) Str::uuid();

            return $this->withHeader('Idempotency-Key', $key)->putJson($this->base.'/trash-commands/'.$key, ['asset_ids' => $ids, 'grace_hours' => $grace]);
        };
        $this->recover = fn (string $task) => $this->withHeader('Idempotency-Key', $task)->putJson($this->base.'/assets/'.$this->asset.'/trash-recovery-tasks/'.$task);
    });

    it('trashes browses and asynchronously recovers verified audio while preserving original identity', function () {
        $second = ($this->publish)();
        $key = (string) Str::uuid();
        ($this->trash)([$this->asset, $second], $key)->assertSuccessful()->assertJsonPath('data.status', 'trashed');
        ($this->trash)([$second, $this->asset], $key)->assertSuccessful();
        ($this->trash)([$this->asset], $key)->assertConflict();
        $this->getJson($this->base.'/assets/'.$this->asset)->assertNotFound();
        $first = $this->getJson($this->base.'/trash?page[size]=1')->assertSuccessful()->assertJsonCount(1, 'data');
        $cursor = $first->json('meta.next_cursor');
        expect($cursor)->toBeString();
        $this->getJson($this->base.'/trash?page[size]=1&page[after]='.urlencode($cursor))->assertSuccessful()->assertJsonCount(1, 'data')->assertJsonPath('meta.next_cursor', null);
        $this->getJson($this->base.'/trash?page[size]=2&page[after]='.urlencode($cursor))->assertUnprocessable();
        $task = (string) Str::uuid();
        ($this->recover)($task)->assertAccepted()->assertJsonPath('data.kind', 'recover_trash');
        $taskUrl = $this->base.'/trash-recovery-tasks/'.$task;
        $this->getJson($taskUrl)->assertSuccessful()->assertJsonPath('data.status', 'queued');
        $this->getJson($taskUrl.'/result')->assertAccepted()->assertHeader('Retry-After', '2');
        app(TrashRecovery::class)->process($task);
        $this->getJson($taskUrl.'/result')->assertSuccessful()->assertJsonPath('data.id', $this->asset);
        ($this->recover)($task)->assertSuccessful()->assertJsonPath('data.status', 'ready');
        $this->getJson($this->base.'/assets/'.$this->asset)->assertSuccessful();
        $this->withHeader('Idempotency-Key', $task)->deleteJson($taskUrl)->assertConflict();
    });

    it('cancels queued recovery and returns safe failed outcomes with retry support', function () {
        ($this->trash)([$this->asset])->assertSuccessful();
        $task = (string) Str::uuid();
        ($this->recover)($task)->assertAccepted();
        $url = $this->base.'/trash-recovery-tasks/'.$task;
        $this->deleteJson($url)->assertSuccessful()->assertJsonPath('data.status', 'cancelled');
        $this->deleteJson($url)->assertSuccessful();
        $this->getJson($url.'/result')->assertConflict();
        app(TrashRecovery::class)->process($task);
        $this->getJson($this->base.'/assets/'.$this->asset)->assertNotFound();
        $task = (string) Str::uuid();
        ($this->recover)($task)->assertAccepted();
        $url = $this->base.'/trash-recovery-tasks/'.$task;
        $blob = 'music/assets/'.hash('sha256', 'studio').'/'.hash('sha256', $this->bytes);
        $this->disk->put($blob, 'bad');
        expect(fn () => app(TrashRecovery::class)->process($task))->toThrow(InvalidAudio::class);
        app(TrashRecovery::class)->failed($task);
        $this->getJson($url)->assertSuccessful()->assertJsonPath('data.error_code', 'trash_recovery_failed');
        $this->getJson($url.'/result')->assertConflict();
        $this->disk->put($blob, $this->bytes);
        ($this->recover)($task)->assertAccepted();
        app(TrashRecovery::class)->process($task);
        $this->getJson($url.'/result')->assertSuccessful();
    });

    it('rejects protected batches and validates lifecycle request identity access and limits', function () {
        $second = ($this->publish)();
        app(MediaRetention::class)->retain($this->actor, new RetainAssets('studio', 'revision:1', [$second]));
        ($this->trash)([$this->asset, $second])->assertConflict();
        $this->getJson($this->base.'/assets/'.$this->asset)->assertSuccessful();
        ($this->trash)([$this->asset], grace: 23)->assertUnprocessable();
        ($this->trash)([$this->asset, $this->asset])->assertUnprocessable();
        ($this->trash)([(string) Str::uuid()])->assertNotFound();
        $this->getJson($this->base.'/trash?page[size]=101')->assertUnprocessable();
        $this->getJson($this->base.'/trash?page[after]=bad')->assertUnprocessable();
        $key = (string) Str::uuid();
        $this->flushHeaders()->putJson($this->base.'/trash-commands/'.$key, ['asset_ids' => [$this->asset]])->assertStatus(428);
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->putJson($this->base.'/trash-commands/'.$key, ['asset_ids' => [$this->asset]])->assertUnprocessable();
        ($this->trash)([$this->asset])->assertSuccessful();
        $task = (string) Str::uuid();
        ($this->recover)($task)->assertAccepted();
        $url = $this->base.'/trash-recovery-tasks/'.$task;
        $this->getJson(str_replace('/studio/', '/other/', $url))->assertNotFound();
        Sanctum::actingAs((new Actor())->forceFill(['id' => 8]), ['music.media.read', 'music.media.restore']);
        $this->getJson($url)->assertNotFound();
        Sanctum::actingAs($this->actor, ['music.media.read']);
        $this->getJson($this->base.'/trash')->assertForbidden();
        ($this->trash)([$second])->assertForbidden();
        ($this->recover)($task)->assertForbidden();
        $this->getJson($url)->assertForbidden();
        $this->getJson($url.'/result')->assertForbidden();
        $this->deleteJson($url)->assertForbidden();
        Sanctum::actingAs($this->actor, ['music.media.read', 'music.media.restore', 'music.media.trash.read', 'music.media.trash.write']);
        $this->denied = 'restore';
        $this->getJson($url)->assertForbidden();
        ($this->recover)($task)->assertForbidden();
        $this->deleteJson($url)->assertForbidden();
        $this->denied = 'trash_list';
        $this->getJson($this->base.'/trash')->assertForbidden();
        $this->denied = 'trash';
        ($this->trash)([$second])->assertForbidden();
    });
});
