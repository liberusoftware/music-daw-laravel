<?php

use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Liberu\Music\Projects\Commands\SaveSession;
use Liberu\Music\Projects\Data\SessionDocument;
use Liberu\Music\Projects\Services\ProjectSessions;
use Liberu\Music\ProjectsApi\MusicProjectsApiServiceProvider;

beforeEach(function () {
    $this->app->register(MusicProjectsApiServiceProvider::class);
    app('router')->getRoutes()->refreshNameLookups();
    $this->actor = User::factory()->create();
    foreach (['list', 'read', 'write'] as $operation) {
        Gate::define('music.projects.'.$operation, fn ($actor, $workspace) => $actor->is($this->actor) && in_array($workspace, ['studio', 'other'], true));
    }
    Sanctum::actingAs($this->actor, ['music.projects.read', 'music.projects.preview']);
    $this->id = (string) Str::uuid();
    $this->base = '/api/v1/music-projects/workspaces/studio';
    for ($version = 0; $version < 3; $version++) {
        app(ProjectSessions::class)->save($this->actor, new SaveSession('studio', $this->id, 'Song '.$version, SessionDocument::empty(), $version, 'save-'.$version, autosave: $version === 1));
    }
});

it('discovers operations and browses typed template filters and cursor pages through the host', function () {
    $response = $this->getJson($this->base.'/capabilities')->assertSuccessful()->assertJsonPath('data.document_schema_versions', [1]);
    expect($response->headers->get('X-Request-ID'))->toBe($response->json('meta.request_id'));
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
    $templateId = (string) Str::uuid();
    app(ProjectSessions::class)->save($this->actor, new SaveSession('studio', $templateId, 'Starter', SessionDocument::empty(), 0, 'template', template: true));
    $first = $this->getJson($this->base.'/sessions?page[size]=1')->assertSuccessful()->assertJsonCount(1, 'data')->assertJsonPath('data.0.project_id', $templateId);
    expect($first->json('data.0.created_at'))->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/');
    $this->getJson($this->base.'/sessions?'.http_build_query(['page' => ['size' => 1, 'after' => $first->json('meta.next_cursor')]]))
        ->assertSuccessful()->assertJsonPath('data.0.project_id', $this->id)->assertJsonPath('meta.next_cursor', null);
    $this->getJson($this->base.'/sessions?templates=true')->assertSuccessful()->assertJsonCount(1, 'data')->assertJsonPath('data.0.project_id', $templateId);
    $this->getJson($this->base.'/sessions?templates=false')->assertSuccessful()->assertJsonCount(1, 'data')->assertJsonPath('data.0.project_id', $this->id);
});

it('resumes metadata history and reads immutable historical snapshots and manifests', function () {
    $this->getJson($this->base.'/sessions/'.$this->id.'/changes')->assertSuccessful()
        ->assertJsonCount(3, 'data.changes')->assertJsonPath('data.latest_version', 3)->assertJsonPath('data.resync_required', false);
    $first = $this->getJson($this->base.'/sessions/'.$this->id.'/revisions?page[size]=2')->assertSuccessful()->assertJsonPath('data.0.version', 3);
    expect(array_column($first->json('data'), 'version'))->toBe([3, 2]);
    $this->getJson($this->base.'/sessions/'.$this->id.'/revisions?'.http_build_query(['page' => ['after' => $first->json('meta.next_cursor')]]))
        ->assertSuccessful()->assertJsonCount(1, 'data')->assertJsonPath('data.0.version', 1)->assertJsonPath('meta.next_cursor', null);
    $this->getJson($this->base.'/sessions/'.$this->id.'?version=1')->assertSuccessful()->assertHeader('ETag', '"1"')->assertJsonPath('data.title', 'Song 0');
    $this->getJson($this->base.'/sessions/'.$this->id.'/manifest?version=2')->assertSuccessful()->assertJsonPath('data.title', 'Song 1');
    $this->getJson(str_replace('/studio', '/other', $this->base).'/sessions/'.$this->id)->assertNotFound();
});

it('rejects malformed browse bounds and cursors with field pointers and safe problem details', function ($query) {
    $response = $this->getJson($this->base.'/sessions?'.$query)->assertUnprocessable()
        ->assertHeader('Content-Type', 'application/problem+json')->assertJsonPath('status', 422);
    expect($response->json('errors.0.code'))->toBe('invalid_field')
        ->and($response->json('errors.0.pointer'))->toStartWith('/')
        ->and($response->getContent())->not->toContain('SQLSTATE', 'vendor/', 'stack');
})->with(['page[size]=101', 'page[after]=invalid!', 'page[after]='.base64_encode('0'), 'templates=invalid', 'page[unknown]=1']);

it('rejects damaged or incompatible encrypted reconnect cursors without accepting their position', function ($payload) {
    $cursor = $payload === null ? 'invalid!' : Crypt::encryptString($payload);
    $this->getJson($this->base.'/sessions/'.$this->id.'/changes?'.http_build_query(['page' => ['after' => $cursor]]))
        ->assertUnprocessable()->assertJsonPath('errors.0.pointer', '/page/after');
})->with([null, 'not-json', '[]', '{"format":"unrecognized","schema_version":1,"version":0}']);

it('requires token abilities even with host workspace permission and reports invalid import JSON safely', function () {
    Sanctum::actingAs($this->actor, []);
    $this->getJson($this->base.'/sessions')->assertForbidden();
    Sanctum::actingAs($this->actor, ['music.projects.preview']);
    $this->postJson($this->base.'/sessions/'.Str::uuid().'/import-preview', ['manifest' => '{'])
        ->assertBadRequest()->assertJsonPath('status', 400)->assertHeader('Content-Type', 'application/problem+json');
});
