<?php

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Liberu\Foundation\Files\Contracts\MalwareScanner;
use Liberu\Music\MediaLibrary\Commands\StartUpload;
use Liberu\Music\MediaLibrary\Commands\UpdateAssetMetadata;
use Liberu\Music\MediaLibrary\Data\UploadBudget;
use Liberu\Music\MediaLibrary\Exceptions\InvalidAudio;
use Liberu\Music\MediaLibrary\Exceptions\MetadataConflict;
use Liberu\Music\MediaLibrary\Exceptions\UploadBudgetExceeded;
use Liberu\Music\MediaLibrary\MusicMediaLibraryServiceProvider;
use Liberu\Music\MediaLibrary\Services\MediaCatalog;
use Liberu\Music\MediaLibrary\Services\MediaUploads;
use Liberu\Music\MediaLibrary\Services\WaveformProcessing;
use Liberu\Music\MediaLibraryApi\Support\ApiProblems;
use Liberu\Music\MediaLibraryApi\Support\CatalogCursor;
use Liberu\Music\MediaLibraryApi\Tests\Fixtures\Actor;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->app->register(MusicMediaLibraryServiceProvider::class);
    $path = dirname((new ReflectionClass(MusicMediaLibraryServiceProvider::class))->getFileName(), 2).'/database/migrations';
    $this->artisan('migrate', ['--path' => $path, '--realpath' => true])->assertSuccessful();
    config()->set('music-media.disk', 'api-media-test');
    $this->disk = Storage::fake('api-media-test');
    $this->actor = (new Actor())->forceFill(['id' => 7]);
    $this->allowed = true;
    foreach (['upload', 'read', 'list', 'quota_read'] as $operation) {
        app(Gate::class)->define('music.media.'.$operation, fn ($actor, $workspace) => $this->allowed && $actor->id === 7 && in_array($workspace, ['studio', 'other']));
    }
    app()->instance(MalwareScanner::class, Mockery::mock(MalwareScanner::class)->shouldReceive('clean')->andReturnTrue()->getMock());
    $users = Mockery::mock(UserProvider::class)->shouldReceive('retrieveById')->with('7')->andReturn($this->actor)->getMock();
    app()->when(WaveformProcessing::class)->needs(UserProvider::class)->give(fn () => $users);
    Bus::fake();
    Sanctum::actingAs($this->actor, ['music.media.read', 'music.media.process', 'music.media.quota.read']);
    $format = pack('vvVVvv', 1, 1, 48000, 96000, 2, 16);
    $chunks = 'fmt '.pack('V', 16).$format.'data'.pack('V', 4).pack('v*', 0, 16384);
    $this->bytes = 'RIFF'.pack('V', strlen($chunks) + 4).'WAVE'.$chunks;
    $this->uploads = app(MediaUploads::class);
    $this->ids = [];
    for ($i = 0; $i < 3; $i++) {
        $id = (string) Str::uuid();
        $this->uploads->start($this->actor, new StartUpload('studio', $id, 'Recording '.$i, strlen($this->bytes), hash('sha256', $this->bytes), []));
        $this->uploads->append($this->actor, 'studio', $id, 0, $this->bytes);
        $this->uploads->complete($this->actor, 'studio', $id);
        $this->ids[] = $id;
    }
    $this->base = '/api/v1/music-media/workspaces/studio';
    $this->taskId = (string) Str::uuid();
    $this->taskUrl = $this->base.'/waveform-tasks/'.$this->taskId;
    $this->queue = fn () => $this->withHeader('Idempotency-Key', $this->taskId)->putJson($this->taskUrl, ['asset_id' => $this->ids[0], 'sample_frames_per_peak' => 1]);
});

it('discovers explicit capabilities and serves verified metadata and authorized budgets', function () {
    $cap = $this->getJson($this->base.'/capabilities')->assertSuccessful()->assertJsonPath('data.schema_version', 1);
    expect($cap->json('data.operations'))->toContain('waveform_cancel')->and($cap->json('data.unsupported'))->toContain('scoped_download_urls');
    expect($cap->headers->get('X-Request-ID'))->toBe($cap->json('meta.request_id'));
    expect($cap->headers->get('Cache-Control'))->toContain('no-store');
    $this->getJson($this->base.'/assets/'.$this->ids[0])->assertSuccessful()->assertJsonPath('data.audio.frames', 2)->assertJsonMissingPath('data.blob_key');
    $this->getJson($this->base.'/upload-budget')->assertSuccessful()->assertJsonPath('data.reserved_bytes', 288);
});

it('paginates keyset searches with encrypted expiring scoped cursors', function () {
    $first = $this->getJson($this->base.'/assets?page[size]=1')->assertSuccessful()->assertJsonCount(1, 'data');
    $cursor = $first->json('meta.next_cursor');
    $next = $this->getJson($this->base.'/assets?'.http_build_query(['page' => ['size' => 1, 'after' => $cursor]]))->assertSuccessful();
    expect($next->json('data.0.id'))->not->toBe($first->json('data.0.id'));
    $this->getJson($this->base.'/assets?'.http_build_query(['page' => ['size' => 2, 'after' => $cursor]]))->assertUnprocessable();
    $this->getJson($this->base.'/assets?'.http_build_query(['filter' => ['query' => 'Recording 1', 'sha256' => hash('sha256', $this->bytes)]]))->assertSuccessful()->assertJsonCount(1, 'data')->assertJsonPath('meta.next_cursor', null);
    $this->getJson($this->base.'/assets?page[size]=101')->assertUnprocessable();
    $this->getJson($this->base.'/assets?filter[tags][0]=%20')->assertUnprocessable();
    $this->getJson($this->base.'/assets?page[after]=forged')->assertUnprocessable();
    $this->travel(16)->minutes();
    $this->getJson($this->base.'/assets?'.http_build_query(['page' => ['size' => 1, 'after' => $cursor]]))->assertUnprocessable();
});

it('queues returns progress reads cached results and rejects changed task replay', function () {
    ($this->queue)()->assertAccepted()->assertJsonPath('data.status', 'queued')->assertJsonPath('data.progress.completed_steps', 0)->assertHeader('Location', $this->taskUrl);
    $this->getJson($this->taskUrl)->assertSuccessful()->assertJsonPath('data.cancellable', true);
    $this->getJson($this->taskUrl.'/result')->assertAccepted()->assertHeader('Retry-After', '2');
    app(WaveformProcessing::class)->process($this->taskId);
    $this->getJson($this->taskUrl.'/result')->assertSuccessful()->assertJsonPath('data.sample_frames', 2)->assertJsonCount(2, 'data.peaks');
    ($this->queue)()->assertSuccessful()->assertJsonPath('data.terminal', true)->assertJsonPath('data.progress.completed_steps', 1);
    $this->putJson($this->taskUrl, ['asset_id' => $this->ids[1]])->assertConflict();
    app(Repository::class)->flush();
    $this->getJson($this->taskUrl.'/result')->assertGone();
    ($this->queue)()->assertAccepted();
});

it('cancels idempotently and never exposes a terminal cancelled result', function () {
    ($this->queue)()->assertAccepted();
    $this->deleteJson($this->taskUrl)->assertSuccessful()->assertJsonPath('data.status', 'cancelled');
    $this->deleteJson($this->taskUrl)->assertSuccessful();
    ($this->queue)()->assertSuccessful()->assertJsonPath('data.status', 'cancelled');
    $this->getJson($this->taskUrl.'/result')->assertConflict();
});

it('requires stable idempotency headers valid JSON and bounded request bytes', function () {
    $this->putJson($this->taskUrl, ['asset_id' => $this->ids[0]])->assertStatus(428);
    $this->withHeader('Idempotency-Key', 'different')->putJson($this->taskUrl, ['asset_id' => $this->ids[0]])->assertUnprocessable()->assertJsonPath('errors.0.pointer', '/headers/Idempotency-Key');
    $this->withHeader('Idempotency-Key', $this->taskId)->putJson($this->taskUrl, ['asset_id' => 'bad', 'sample_frames_per_peak' => '1'])->assertUnprocessable();
    $this->call('PUT', $this->taskUrl, [], [], [], ['CONTENT_TYPE' => 'text/plain'], '{}')->assertStatus(415);
    $this->putJson($this->taskUrl, ['asset_id' => $this->ids[0], 'extra' => str_repeat('x', 8192)])->assertStatus(413);
});

it('rechecks revoked access wrong workspaces scopes and authentication', function () {
    ($this->queue)()->assertAccepted();
    $this->getJson(str_replace('/studio/', '/other/', $this->taskUrl))->assertNotFound();
    $this->getJson(str_replace('/studio', '/other', $this->base).'/assets/'.$this->ids[0])->assertNotFound();
    $this->allowed = false;
    $this->getJson($this->taskUrl)->assertForbidden();
    $this->deleteJson($this->taskUrl)->assertForbidden();
    $this->allowed = true;
    Sanctum::actingAs($this->actor, ['music.media.read']);
    $this->putJson($this->taskUrl, ['asset_id' => $this->ids[0]])->assertForbidden();
    $this->getJson($this->base.'/upload-budget')->assertForbidden();
    app('auth')->forgetGuards();
    $this->getJson($this->base.'/capabilities')->assertUnauthorized()->assertHeader('Content-Type', 'application/problem+json');
});

it('refuses use when the core provider is not loaded', function () {
    $provider = $this->app->getProvider(MusicMediaLibraryServiceProvider::class);
    $property = new ReflectionProperty($this->app, 'serviceProviders');
    $property->setValue($this->app, array_values(array_filter($property->getValue($this->app), fn ($item) => $item !== $provider)));
    $this->getJson($this->base.'/capabilities')->assertStatus(503);
});

it('renders sanitized problems and leaves unrelated routes alone', function () {
    $renderer = new ApiProblems();
    expect($renderer->render(new RuntimeException('secret'), Request::create('/other')))->toBeNull();
    foreach ([new InvalidAudio('/private/audio'), new MetadataConflict('private'), new UploadBudgetExceeded(new UploadBudget('studio', 0, 0), 96), new JsonException('private'), new RuntimeException('private'), new HttpException(429, 'private', headers: ['Retry-After' => '4'])] as $exception) {
        $response = $renderer->render($exception, Request::create($this->base.'/assets'));
        expect($response->getContent())->not->toContain('private')->and($response->headers->get('Content-Type'))->toBe('application/problem+json');
    }
    expect($response->headers->get('Retry-After'))->toBe('4');
});

it('rejects malformed encrypted cursors without granting access', function () {
    $cursors = new CatalogCursor();
    foreach ([Crypt::encryptString('not json'), Crypt::encryptString('null'), Crypt::encryptString('{"format":"other"}')] as $value) {
        expect(fn () => $cursors->decode($value, '7', 'studio', []))->toThrow(ValidationException::class);
    }
    $cursor = $cursors->encode('7', 'studio', [], (string) Str::uuid());
    expect(fn () => $cursors->decode($cursor, '8', 'studio', []))->toThrow(ValidationException::class);
});

it('keeps every public route within the reviewed OpenAPI contract', function () {
    $spec = json_decode(file_get_contents(__DIR__.'/../../openapi/media.json'), true, flags: JSON_THROW_ON_ERROR);
    $operations = [];
    foreach ($spec['paths'] as $path => $methods) {
        foreach ($methods as $method => $operation) {
            if ($method !== 'parameters') {
                $operations[] = strtoupper($method).' '.ltrim($path, '/');
            }
        }
    }
    $routes = [];
    foreach (Route::getRoutes() as $route) {
        if (str_starts_with($route->getName() ?? '', 'music.media.api.')) {
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $routes[] = $method.' '.$route->uri();
            }
        }
    }
    sort($operations);
    sort($routes);
    expect($routes)->toBe($operations);
});

it('normalizes catalog tags through the authorized core metadata contract', function () {
    app(Gate::class)->define('music.media.annotate', fn () => true);
    app(MediaCatalog::class)->update($this->actor,
        new UpdateAssetMetadata('studio', $this->ids[0], 'Kick', ['Drums', 'Kit'], 0, 'tag'));
    $this->getJson($this->base.'/assets?'.http_build_query(['filter' => ['tags' => [' KIT ', 'drums']]]))->assertSuccessful()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->ids[0])->assertJsonPath('data.0.name', 'Kick');
});

it('throttles the authenticated route group and preserves retry headers', function () {
    for ($i = 0; $i < 60; $i++) {
        $this->getJson($this->base.'/capabilities')->assertSuccessful();
    }
    $this->getJson($this->base.'/capabilities')->assertTooManyRequests()->assertHeader('Retry-After')->assertHeader('Content-Type', 'application/problem+json');
});

it('returns current annotated metadata and availability for a missing verified source', function () {
    app(Gate::class)->define('music.media.annotate', fn () => true);
    app(MediaCatalog::class)->update($this->actor, new UpdateAssetMetadata('studio', $this->ids[0], 'Edited', ['Voice'], 0, 'edit'));
    $this->getJson($this->base.'/assets/'.$this->ids[0])->assertSuccessful()->assertJsonPath('data.name', 'Edited')->assertJsonPath('data.tags.0', 'voice')->assertJsonPath('data.available', true);
    $this->disk->delete('music/assets/'.hash('sha256', 'studio').'/'.hash('sha256', $this->bytes));
    $this->getJson($this->base.'/assets/'.$this->ids[0])->assertSuccessful()->assertJsonPath('data.available', false);
});
