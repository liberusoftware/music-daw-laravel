<?php

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Sanctum;
use Liberu\Foundation\Files\Contracts\MediaAccess;
use Liberu\Music\Projects\Commands\SaveSession;
use Liberu\Music\Projects\Contracts\SessionMediaRetention;
use Liberu\Music\Projects\Contracts\VerifiedMedia;
use Liberu\Music\Projects\Data\SessionDocument;
use Liberu\Music\Projects\MusicProjectsServiceProvider;
use Liberu\Music\Projects\Services\ProjectSessions;
use Liberu\Music\ProjectsApi\Http\Controllers\SessionCommandsController;
use Liberu\Music\ProjectsApi\Http\Controllers\SessionQueriesController;
use Liberu\Music\ProjectsApi\Support\ApiProblems;
use Liberu\Music\ProjectsApi\Tests\Fixtures\Actor;

beforeEach(function () {
    $this->app->register(MusicProjectsServiceProvider::class);
    $this->artisan('migrate', ['--path' => realpath(__DIR__.'/../../vendor/liberusoftware/music-projects/database/migrations'), '--realpath' => true])->assertSuccessful();
    $this->actor = (new Actor())->forceFill(['id' => 1]);
    foreach (['list', 'read', 'write'] as $operation) {
        app(Gate::class)->define('music.projects.'.$operation, fn ($actor, $workspace) => $actor->id === 1 && in_array($workspace, ['studio', 'other']));
    }
    Sanctum::actingAs($this->actor, ['music.projects.read', 'music.projects.preview']);
    $this->id = (string) Str::uuid();
    $this->base = '/api/v1/music-projects/workspaces/studio';
    $this->sessions = app(ProjectSessions::class);
    for ($version = 0; $version < 3; $version++) {
        $this->sessions->save($this->actor, new SaveSession('studio', $this->id, 'Song '.$version, SessionDocument::empty(), $version, 'save-'.$version, $version === 1));
    }
});

it('discovers supported metadata operations without implying native audio capabilities', function () {
    $response = $this->getJson($this->base.'/capabilities')->assertSuccessful()
        ->assertJsonPath('data.document_schema_versions', [1])->assertJsonPath('data.unsupported.0', 'revision_stream');
    expect($response->headers->get('X-Request-ID'))->toBe($response->json('meta.request_id'));
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});

it('browses bounded current sessions with opaque cursors and template filters', function () {
    $template = (string) Str::uuid();
    $this->sessions->save($this->actor, new SaveSession('studio', $template, 'Template', SessionDocument::empty(), 0, 'template', template: true));
    $first = $this->getJson($this->base.'/sessions?page[size]=1')->assertSuccessful()->assertJsonCount(1, 'data');
    $cursor = $first->json('meta.next_cursor');
    expect($cursor)->toBeString();
    $this->getJson($this->base.'/sessions?'.http_build_query(['page' => ['size' => 1, 'after' => $cursor]]))
        ->assertSuccessful()->assertJsonPath('data.0.project_id', $this->id)->assertJsonPath('meta.next_cursor', null);
    $this->getJson($this->base.'/sessions?templates=false')->assertSuccessful()->assertJsonPath('data.0.project_id', $this->id)->assertJsonCount(1, 'data');
    $this->getJson($this->base.'/sessions?templates=true')->assertSuccessful()->assertJsonPath('data.0.project_id', $template);
    $this->getJson($this->base.'/sessions?templates=1')->assertSuccessful()->assertJsonPath('data.0.project_id', $template)->assertJsonCount(1, 'data');
});

it('reads current and historical versions and resumes revision history', function () {
    $this->getJson($this->base.'/sessions/'.$this->id)->assertSuccessful()->assertJsonPath('data.version', 3)->assertHeader('ETag', '"3"');
    $this->getJson($this->base.'/sessions/'.$this->id.'?version=1')->assertSuccessful()->assertJsonPath('data.version', 1);
    $first = $this->getJson($this->base.'/sessions/'.$this->id.'/revisions?page[size]=2')->assertSuccessful()->assertJsonPath('data.0.version', 3);
    $this->getJson($this->base.'/sessions/'.$this->id.'/revisions?'.http_build_query(['page' => ['size' => 2, 'after' => $first->json('meta.next_cursor')]]))
        ->assertSuccessful()->assertJsonCount(1, 'data')->assertJsonPath('data.0.version', 1)->assertJsonPath('meta.next_cursor', null);
});

it('exports portable manifests and previews import without persisting a destination', function () {
    $manifest = $this->getJson($this->base.'/sessions/'.$this->id.'/manifest?version=1')->assertSuccessful()->json('data');
    expect($manifest['checksum'])->toBe(SessionDocument::empty()->checksum());
    $destination = (string) Str::uuid();
    $this->postJson($this->base.'/sessions/'.$destination.'/import-preview', ['manifest' => json_encode($manifest)])
        ->assertSuccessful()->assertJsonPath('data.title', 'Song 0')->assertJsonPath('data.asset_count', 0);
    expect(DB::table('music_projects')->where('id', $destination)->exists())->toBeFalse()->and(DB::table('music_project_revisions')->count())->toBe(3);
    $this->getJson($this->base.'/sessions/'.$this->id.'/manifest')->assertSuccessful()->assertJsonPath('data.title', 'Song 2');
});

it('returns safe problem details for revoked permissions and wrong workspace reads', function () {
    $this->getJson(str_replace('/studio', '/other', $this->base).'/sessions/'.$this->id)->assertNotFound()->assertJsonPath('status', 404);
    app(Gate::class)->define('music.projects.read', fn () => false);
    $response = $this->getJson($this->base.'/sessions/'.$this->id)->assertForbidden()->assertHeader('Content-Type', 'application/problem+json');
    expect($response->json('request_id'))->toBe($response->headers->get('X-Request-ID'));
    expect($response->getContent())->not->toContain('SQL', 'stack', 'vendor/', 'music_project_revisions');
});

it('requires token abilities independently of core permissions', function () {
    Sanctum::actingAs($this->actor, []);
    $this->getJson($this->base.'/capabilities')->assertForbidden();
    Sanctum::actingAs($this->actor, ['music.projects.read']);
    $this->postJson($this->base.'/sessions/'.$this->id.'/import-preview', ['manifest' => '{}'])->assertForbidden();
});

it('validates query bounds and opaque cursors without exposing internal exceptions', function (string $query) {
    $this->getJson($this->base.'/sessions?'.$query)->assertUnprocessable()->assertJsonPath('status', 422);
})->with(['page[size]=101', 'page[after]=invalid!', 'page[after]=MA==', 'page[after]=OTk5OTk5OTk5OTk5OTk5OTk5OTk5OTk5OTk5OTk5OTk5OTk5OTk5OTk5OTk5OTk5OTk5OTk5OTk5OTk5OTk5OTk5OTk5OTk5OTk5OTk5OTk5OTk5OTk5OTk5', 'templates=invalid']);

it('rejects malformed portable JSON and unsupported schema versions', function () {
    $this->postJson($this->base.'/sessions/'.$this->id.'/import-preview', ['manifest' => '{'])->assertStatus(400);
    $this->postJson($this->base.'/sessions/'.$this->id.'/import-preview', ['manifest' => '{}'])->assertUnprocessable()->assertJsonStructure(['errors' => [['code', 'pointer', 'message']]]);
    $this->getJson($this->base.'/sessions/'.$this->id.'?version=0')->assertUnprocessable();
});

it('limits request bursts and supplies retry guidance', function () {
    for ($attempt = 0; $attempt < 60; $attempt++) {
        $this->getJson($this->base.'/capabilities')->assertSuccessful();
    }
    $this->getJson($this->base.'/capabilities')->assertStatus(429)->assertHeader('Retry-After');
});

it('redacts unexpected failures and leaves unrelated host exception rendering alone', function () {
    Route::get('/api/v1/music-projects/failure-check', function () {
        throw new RuntimeException('secret database connection password');
    });
    $response = $this->getJson('/api/v1/music-projects/failure-check')->assertStatus(500)->assertJsonPath('status', 500);
    expect($response->getContent())->not->toContain('password', 'trace', 'vendor/');
    expect(app(ApiProblems::class)->render(new RuntimeException(), Request::create('/unrelated-host-page')))->toBeNull();
});

it('revalidates asset access before exposing snapshots through any metadata presentation', function () {
    $asset = ['id' => (string) Str::uuid(), 'sha256' => str_repeat('a', 64), 'sample_rate' => 48000, 'channels' => 1, 'frames' => 2];
    $document = SessionDocument::empty()->jsonSerialize();
    $document['assets'] = [$asset];
    $mediaAccess = Mockery::mock(MediaAccess::class);
    $mediaAccess->shouldReceive('authorized')->andReturn(true);
    app()->instance(MediaAccess::class, $mediaAccess);
    $verified = Mockery::mock(VerifiedMedia::class);
    $verified->shouldReceive('resolve')->once()->andReturn($asset);
    app()->instance(VerifiedMedia::class, $verified);
    $retention = Mockery::mock(SessionMediaRetention::class);
    $retention->shouldReceive('retain')->once();
    app()->instance(SessionMediaRetention::class, $retention);
    $id = (string) Str::uuid();
    $revision = app(ProjectSessions::class)->save($this->actor, new SaveSession('studio', $id, 'Private media', SessionDocument::fromArray($document), 0, 'private'));
    $denied = Mockery::mock(MediaAccess::class);
    $denied->shouldReceive('authorized')->andReturn(false);
    app()->instance(MediaAccess::class, $denied);
    foreach (['', '/manifest'] as $suffix) {
        $this->getJson($this->base.'/sessions/'.$id.$suffix)->assertForbidden();
    }
    $this->getJson($this->base.'/sessions')->assertSuccessful()->assertJsonMissingPath('data.0.document');
    $this->getJson($this->base.'/sessions/'.$id.'/revisions')->assertSuccessful()->assertJsonMissingPath('data.0.document');
    $manifest = json_encode(['format' => 'liberu.music.session', 'format_version' => 1, 'title' => 'Private media', 'document' => $revision->document, 'checksum' => $revision->document->checksum()]);
    $this->postJson($this->base.'/sessions/'.Str::uuid().'/import-preview', ['manifest' => $manifest])->assertForbidden();
});

it('keeps every exposed operation and required token ability in its OpenAPI fragment', function () {
    $contract = json_decode(file_get_contents(__DIR__.'/../../openapi/projects.json'), true, flags: JSON_THROW_ON_ERROR);
    expect($contract['openapi'])->toBe('3.1.0');
    $registered = [];
    foreach (app('router')->getRoutes() as $route) {
        if (! str_starts_with($route->getActionName(), SessionQueriesController::class.'@') && ! str_starts_with($route->getActionName(), SessionCommandsController::class.'@')) {
            continue;
        }
        $path = '/'.$route->uri();
        $method = strtolower($route->methods()[0]);
        $operation = $contract['paths'][$path][$method];
        foreach ($operation['x-required-abilities'] as $ability) {
            expect($route->gatherMiddleware())->toContain(CheckAbilities::class.':'.$ability);
        }
        $registered[] = $operation['operationId'];
    }
    $documented = [];
    foreach ($contract['paths'] as $path) {
        foreach ($path as $operation) {
            $documented[] = $operation['operationId'];
        }
    }
    sort($registered);
    sort($documented);
    expect($registered)->toBe($documented)->toHaveCount(10)->and(array_unique($registered))->toHaveCount(10);
});
