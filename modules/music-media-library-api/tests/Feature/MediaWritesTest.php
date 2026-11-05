<?php

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Liberu\Foundation\Files\Contracts\MalwareScanner;
use Liberu\Music\MediaLibrary\Commands\RetainAssets;
use Liberu\Music\MediaLibrary\Exceptions\InvalidAudio;
use Liberu\Music\MediaLibrary\MusicMediaLibraryServiceProvider;
use Liberu\Music\MediaLibrary\Services\AssetProcessing;
use Liberu\Music\MediaLibrary\Services\MediaRetention;
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
