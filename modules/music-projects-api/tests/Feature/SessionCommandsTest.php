<?php

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Liberu\Music\Projects\Data\SessionDocument;
use Liberu\Music\Projects\Events\SessionSaved;
use Liberu\Music\Projects\MusicProjectsServiceProvider;
use Liberu\Music\ProjectsApi\Tests\Fixtures\Actor;

beforeEach(function () {
    $this->app->register(MusicProjectsServiceProvider::class);
    $this->artisan('migrate', ['--path' => realpath(__DIR__.'/../../vendor/liberusoftware/music-projects/database/migrations'), '--realpath' => true])->assertSuccessful();
    $this->actor = (new Actor())->forceFill(['id' => 1]);
    foreach (['list', 'read', 'write'] as $operation) {
        app(Gate::class)->define('music.projects.'.$operation, fn ($actor, $workspace) => $actor->id === 1 && $workspace === 'studio');
    }
    Sanctum::actingAs($this->actor, ['music.projects.read', 'music.projects.write']);
    $this->id = (string) Str::uuid();
    $this->base = '/api/v1/music-projects/workspaces/studio/sessions/'.$this->id;
    $this->payload = ['title' => 'Song', 'document' => SessionDocument::empty()->jsonSerialize()];
    $this->headers = fn (int $version, string $key): array => ['If-Match' => '"'.$version.'"', 'Idempotency-Key' => $key];
});

it('creates checkpoints and autosaves with immutable locations and replay safe results after later edits', function () {
    $first = $this->postJson($this->base.'/checkpoints', $this->payload, ($this->headers)(0, 'create'))
        ->assertCreated()->assertJsonPath('data.version', 1)->assertHeader('ETag', '"1"');
    $this->getJson($first->headers->get('Location'))->assertSuccessful()->assertJsonPath('data.version', 1);
    $this->postJson($this->base.'/checkpoints', array_replace($this->payload, ['title' => 'Draft', 'autosave' => true]), ($this->headers)(1, 'draft'))
        ->assertCreated()->assertJsonPath('data.version', 2)->assertJsonPath('data.autosave', true);
    $this->postJson($this->base.'/checkpoints', $this->payload, ($this->headers)(0, 'create'))
        ->assertCreated()->assertJsonPath('data.version', 1)->assertJsonPath('data.title', 'Song')->assertHeader('ETag', '"1"');
    expect(DB::table('music_project_revisions')->count())->toBe(2)->and(DB::table('music_projects')->value('version'))->toBe(2);
});

it('returns different typed outcomes for stale preconditions and conflicting command keys', function () {
    $this->postJson($this->base.'/checkpoints', $this->payload, ($this->headers)(0, 'create'))->assertCreated();
    $this->postJson($this->base.'/checkpoints', $this->payload, ($this->headers)(0, 'stale'))
        ->assertStatus(412)->assertJsonPath('status', 412);
    $this->postJson($this->base.'/checkpoints', array_replace($this->payload, ['title' => 'Changed']), ($this->headers)(0, 'create'))
        ->assertConflict()->assertJsonPath('status', 409);
    expect(DB::table('music_project_revisions')->count())->toBe(1);
});

it('requires exact quoted preconditions and bounded command identities', function (array $headers, int $status) {
    $this->postJson($this->base.'/checkpoints', $this->payload, $headers)->assertStatus($status)->assertJsonPath('status', $status);
    expect(DB::table('music_projects')->count())->toBe(0);
})->with([
    [[], 428],
    [['If-Match' => '"0"'], 422],
    [['If-Match' => 'W/"0"', 'Idempotency-Key' => 'key'], 422],
    [['If-Match' => '*', 'Idempotency-Key' => 'key'], 422],
    [['If-Match' => '"-1"', 'Idempotency-Key' => 'key'], 422],
    [['If-Match' => '"9223372036854775807"', 'Idempotency-Key' => 'key'], 422],
    [['If-Match' => '"0"', 'Idempotency-Key' => str_repeat('x', 129)], 422],
]);

it('rejects invalid document schemas and non boolean save flags', function () {
    $bad = $this->payload;
    $bad['document']['schema_version'] = 999;
    $this->postJson($this->base.'/checkpoints', $bad, ($this->headers)(0, 'schema'))->assertUnprocessable();
    $this->postJson($this->base.'/checkpoints', array_replace($this->payload, ['autosave' => 'true']), ($this->headers)(0, 'flag'))->assertUnprocessable();
    expect(DB::table('music_projects')->count())->toBe(0);
});

it('treats actual headers as authoritative over injected body preconditions', function () {
    $payload = $this->payload + ['headers' => ['If-Match' => '"99"', 'Idempotency-Key' => 'injected'], 'expected_version' => 99];
    $this->postJson($this->base.'/checkpoints', $payload, ($this->headers)(0, 'actual'))->assertCreated();
    expect(DB::table('music_project_revisions')->value('idempotency_key'))->toBe('actual');
});

it('recovers historical autosaves as new checkpoints and replays recovery without replacing newer work', function () {
    $this->postJson($this->base.'/checkpoints', array_replace($this->payload, ['autosave' => true]), ($this->headers)(0, 'draft'))->assertCreated();
    $this->postJson($this->base.'/checkpoints', array_replace($this->payload, ['title' => 'Later']), ($this->headers)(1, 'later'))->assertCreated();
    $this->postJson($this->base.'/recoveries', ['source_version' => 1], ($this->headers)(2, 'recover'))
        ->assertCreated()->assertJsonPath('data.version', 3)->assertJsonPath('data.title', 'Song')->assertJsonPath('data.autosave', false);
    $this->postJson($this->base.'/checkpoints', array_replace($this->payload, ['title' => 'Newest']), ($this->headers)(3, 'newest'))->assertCreated();
    $this->postJson($this->base.'/recoveries', ['source_version' => 1], ($this->headers)(2, 'recover'))->assertCreated()->assertJsonPath('data.version', 3);
    $this->getJson($this->base)->assertSuccessful()->assertJsonPath('data.title', 'Newest');
    $this->postJson($this->base.'/recoveries', ['source_version' => 1], ($this->headers)(2, 'stale-recover'))->assertStatus(412);
    app(Gate::class)->define('music.projects.read', fn () => false);
    $this->postJson($this->base.'/recoveries', ['source_version' => 1], ($this->headers)(2, 'recover'))->assertForbidden();
});

it('confirms portable imports into new identities and deduplicates exact retries', function () {
    $this->postJson($this->base.'/checkpoints', $this->payload, ($this->headers)(0, 'source'))->assertCreated();
    $manifest = $this->getJson($this->base.'/manifest')->assertSuccessful()->json('data');
    $target = '/api/v1/music-projects/workspaces/studio/sessions/'.Str::uuid().'/imports';
    $body = ['manifest' => json_encode($manifest)];
    $this->postJson($target, $body, ($this->headers)(0, 'import'))->assertCreated()->assertJsonPath('data.version', 1)->assertHeader('ETag', '"1"');
    $this->postJson($target, $body, ($this->headers)(0, 'import'))->assertCreated()->assertJsonPath('data.version', 1);
    $manifest['title'] = 'Different intent';
    $this->postJson($target, ['manifest' => json_encode($manifest)], ($this->headers)(0, 'import'))->assertConflict();
    $this->postJson($target, $body, ($this->headers)(1, 'import'))->assertUnprocessable();
    $this->postJson($target, ['manifest' => '{'], ($this->headers)(0, 'malformed'))->assertStatus(400);
    $manifest['checksum'] = str_repeat('f', 64);
    $this->postJson($target, ['manifest' => json_encode($manifest)], ($this->headers)(0, 'corrupt'))->assertUnprocessable();
    expect(DB::table('music_project_revisions')->count())->toBe(2);
});

it('rechecks scope and owner access on writes and replays without trusting a prior preview or save', function () {
    Sanctum::actingAs($this->actor, ['music.projects.read']);
    $this->postJson($this->base.'/checkpoints', $this->payload, ($this->headers)(0, 'create'))->assertForbidden();
    Sanctum::actingAs($this->actor, ['music.projects.write']);
    $this->postJson($this->base.'/checkpoints', $this->payload, ($this->headers)(0, 'create'))->assertCreated();
    app(Gate::class)->define('music.projects.write', fn () => false);
    $this->postJson($this->base.'/checkpoints', $this->payload, ($this->headers)(0, 'create'))->assertForbidden();
    expect(DB::table('music_project_revisions')->count())->toBe(1);
});

it('rejects unavailable referenced media and preserves workspace isolation on writes', function () {
    $payload = $this->payload;
    $payload['document']['assets'] = [['id' => 'missing', 'sha256' => str_repeat('a', 64), 'sample_rate' => 48000, 'channels' => 1, 'frames' => 2]];
    $this->postJson($this->base.'/checkpoints', $payload, ($this->headers)(0, 'media'))->assertForbidden();
    $this->postJson(str_replace('/studio/', '/other/', $this->base).'/checkpoints', $this->payload, ($this->headers)(0, 'workspace'))->assertForbidden();
    expect(DB::table('music_projects')->count())->toBe(0);
});

it('rolls back failed writes and permits retry with the same command identity after recovery', function () {
    $events = [];
    app(Dispatcher::class)->listen(SessionSaved::class, function ($event) use (&$events) {
        $events[] = $event;
    });
    DB::unprepared("CREATE TRIGGER refuse_api_revision BEFORE INSERT ON music_project_revisions BEGIN SELECT RAISE(ABORT, 'internal secret'); END");
    $failure = $this->postJson($this->base.'/checkpoints', $this->payload, ($this->headers)(0, 'retry'))->assertStatus(500);
    expect($failure->getContent())->not->toContain('internal secret', 'SQLSTATE')
        ->and(DB::table('music_projects')->count())->toBe(0)->and($events)->toBe([]);
    DB::unprepared('DROP TRIGGER refuse_api_revision');
    $this->postJson($this->base.'/checkpoints', $this->payload, ($this->headers)(0, 'retry'))->assertCreated()->assertJsonPath('data.version', 1);
    expect($events)->toHaveCount(1);
});

it('preserves the workspace boundary even when the actor can write both workspaces', function () {
    $this->postJson($this->base.'/checkpoints', $this->payload, ($this->headers)(0, 'studio'))->assertCreated();
    app(Gate::class)->define('music.projects.write', fn () => true);
    $this->postJson(str_replace('/studio/', '/other/', $this->base).'/checkpoints', $this->payload, ($this->headers)(0, 'other'))->assertNotFound();
    expect(DB::table('music_project_revisions')->count())->toBe(1)->and(DB::table('music_projects')->value('workspace_id'))->toBe('studio');
});

it('binds command fingerprints to the actor and supports template checkpoint flags', function () {
    $payload = $this->payload + ['template' => true];
    $this->postJson($this->base.'/checkpoints', $payload, ($this->headers)(0, 'template'))->assertCreated()->assertJsonPath('data.template', true);
    app(Gate::class)->define('music.projects.write', fn () => true);
    Sanctum::actingAs((new Actor())->forceFill(['id' => 2]), ['music.projects.write']);
    $this->postJson($this->base.'/checkpoints', $payload, ($this->headers)(0, 'template'))->assertConflict();
    expect(DB::table('music_project_revisions')->count())->toBe(1);
});

it('distinguishes immutable template state conflicts from current revision preconditions', function () {
    $this->postJson($this->base.'/checkpoints', $this->payload + ['template' => true], ($this->headers)(0, 'template'))->assertCreated();
    $this->postJson($this->base.'/checkpoints', $this->payload, ($this->headers)(1, 'change-type'))->assertConflict()
        ->assertJsonPath('type', 'https://docs.liberusoftware.com/problems/music-projects-template-conflict');
    $this->postJson($this->base.'/checkpoints', array_replace($this->payload, ['title' => 'Changed', 'template' => true]), ($this->headers)(0, 'template'))->assertConflict()
        ->assertJsonPath('type', 'https://docs.liberusoftware.com/problems/music-projects-idempotency-conflict');
    $this->postJson($this->base.'/checkpoints', $this->payload + ['template' => true], ($this->headers)(1, 'update'))->assertCreated()->assertJsonPath('data.version', 2);
});

it('does not expose historical content through recovery to a write-only delegated token', function () {
    $this->postJson($this->base.'/checkpoints', $this->payload, ($this->headers)(0, 'create'))->assertCreated();
    Sanctum::actingAs($this->actor, ['music.projects.write']);
    $this->postJson($this->base.'/recoveries', ['source_version' => 1], ($this->headers)(1, 'recover'))->assertForbidden();
    expect(DB::table('music_project_revisions')->count())->toBe(1);
});
