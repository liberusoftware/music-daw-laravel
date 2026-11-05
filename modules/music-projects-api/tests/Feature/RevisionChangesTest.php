<?php

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Liberu\Music\Projects\Commands\SaveSession;
use Liberu\Music\Projects\Data\SessionDocument;
use Liberu\Music\Projects\MusicProjectsServiceProvider;
use Liberu\Music\Projects\Services\ProjectSessions;
use Liberu\Music\ProjectsApi\Support\RevisionCursor;
use Liberu\Music\ProjectsApi\Tests\Fixtures\Actor;

beforeEach(function () {
    $this->app->register(MusicProjectsServiceProvider::class);
    $this->artisan('migrate', ['--path' => realpath(__DIR__.'/../../vendor/liberusoftware/music-projects/database/migrations'), '--realpath' => true])->assertSuccessful();
    $this->actor = (new Actor())->forceFill(['id' => 1]);
    foreach (['list', 'read', 'write'] as $operation) {
        app(Gate::class)->define('music.projects.'.$operation, fn ($actor, $workspace) => $actor->id === 1 && in_array($workspace, ['studio', 'other']));
    }
    Sanctum::actingAs($this->actor, ['music.projects.read']);
    $this->id = (string) Str::uuid();
    $this->base = '/api/v1/music-projects/workspaces/studio/sessions/'.$this->id;
    $this->sessions = app(ProjectSessions::class);
    for ($version = 0; $version < 3; $version++) {
        $this->sessions->save($this->actor, new SaveSession('studio', $this->id, 'Song '.$version, SessionDocument::empty(), $version, 'save-'.$version, $version === 1));
    }
    $this->poll = function (?string $cursor = null, int $limit = 2) {
        $page = ['size' => $limit];
        if ($cursor !== null) {
            $page['after'] = $cursor;
        }

        return $this->getJson($this->base.'/changes?'.http_build_query(['page' => $page]));
    };
});

it('paginates ordered durable notices with opaque scoped reconnect cursors and no document payloads', function () {
    $first = ($this->poll)()->assertSuccessful()->assertJsonPath('data.latest_version', 3)
        ->assertJsonPath('data.has_more', true)->assertJsonPath('data.resync_required', false);
    expect(array_column($first->json('data.changes'), 'version'))->toBe([1, 2])
        ->and(array_column($first->json('data.changes'), 'autosave'))->toBe([false, true])
        ->and($first->json('data.changes.0.created_at'))->toEndWith('Z')
        ->and($first->getContent())->not->toContain('"document"', '"actor_id"', '"assets"');
    $cursor = $first->json('meta.next_cursor');
    expect($cursor)->toBeString()->and(strlen($cursor))->toBeLessThanOrEqual(2048)
        ->and($cursor)->not->toContain($this->id, 'studio');
    $last = ($this->poll)($cursor)->assertSuccessful()->assertJsonPath('data.has_more', false);
    expect(array_column($last->json('data.changes'), 'version'))->toBe([3]);
    ($this->poll)($last->json('meta.next_cursor'))->assertSuccessful()->assertJsonPath('data.changes', [])->assertJsonPath('data.resync_required', false);
    expect($last->headers->get('X-Request-ID'))->toBe($last->json('meta.request_id'));
});

it('resumes from an authorized snapshot and retries a lost response without skipping later commits', function () {
    $snapshot = $this->getJson($this->base.'?version=1')->assertSuccessful()->assertHeader('ETag', '"1"');
    $cursor = $snapshot->json('meta.changes_cursor');
    $lost = ($this->poll)($cursor)->assertSuccessful();
    expect(array_column($lost->json('data.changes'), 'version'))->toBe([2, 3]);
    $this->sessions->save($this->actor, new SaveSession('studio', $this->id, 'Later edit', SessionDocument::empty(), 3, 'later'));
    $retry = ($this->poll)($cursor)->assertSuccessful()->assertJsonPath('data.latest_version', 4)->assertJsonPath('data.has_more', true);
    expect($retry->json('data.changes'))->toBe($lost->json('data.changes'));
    $next = ($this->poll)($retry->json('meta.next_cursor'))->assertSuccessful();
    expect(array_column($next->json('data.changes'), 'version'))->toBe([4]);
    expect(DB::table('music_project_revisions')->count())->toBe(4);
});

it('requires an explicit snapshot resync after a sequence gap without returning a partial page', function () {
    DB::table('music_project_revisions')->where('project_id', $this->id)->where('version', 2)->delete();
    $gap = ($this->poll)(null, 100)->assertSuccessful()->assertJsonPath('data.resync_required', true)
        ->assertJsonPath('data.changes', [])->assertJsonPath('meta.next_cursor', null)->assertJsonPath('data.has_more', false);
    $snapshot = $this->getJson($gap->json('links.snapshot'))->assertSuccessful()->assertJsonPath('data.version', 3);
    ($this->poll)($snapshot->json('meta.changes_cursor'))->assertSuccessful()->assertJsonPath('data.changes', [])->assertJsonPath('data.resync_required', false);
});

it('links to the exact upper snapshot and cannot silently substitute an older snapshot for missing tail data', function () {
    $cursor = $this->getJson($this->base.'?version=1')->assertSuccessful()->json('meta.changes_cursor');
    DB::table('music_project_revisions')->where('project_id', $this->id)->where('version', '>', 1)->delete();
    $gap = ($this->poll)($cursor)->assertSuccessful()->assertJsonPath('data.resync_required', true)->assertJsonPath('meta.next_cursor', null);
    expect($gap->json('links.snapshot'))->toEndWith('?version=3');
    $this->getJson($gap->json('links.snapshot'))->assertNotFound();
});

it('requests resynchronization when a saved cursor is ahead of a restored authoritative database', function () {
    $cursor = app(RevisionCursor::class)->encode('studio', $this->id, 99);
    $response = ($this->poll)($cursor)->assertSuccessful()->assertJsonPath('data.latest_version', 3)
        ->assertJsonPath('data.resync_required', true)->assertJsonPath('data.changes', [])->assertJsonPath('meta.next_cursor', null);
    $this->getJson($response->json('links.snapshot'))->assertSuccessful()->assertJsonPath('data.version', 3);
});

it('rejects cursors copied to another workspace or session and rechecks revoked access on every poll', function () {
    $cursor = ($this->poll)()->assertSuccessful()->json('meta.next_cursor');
    $query = http_build_query(['page' => ['after' => $cursor]]);
    $this->getJson(str_replace('/studio/', '/other/', $this->base).'/changes?'.$query)->assertUnprocessable();
    $this->getJson(str_replace($this->id, (string) Str::uuid(), $this->base).'/changes?'.$query)->assertUnprocessable();
    $this->getJson(str_replace('/studio/', '/other/', $this->base).'/changes')->assertNotFound();
    app(Gate::class)->define('music.projects.read', fn () => false);
    ($this->poll)($cursor)->assertForbidden();
    Sanctum::actingAs($this->actor, []);
    ($this->poll)($cursor)->assertForbidden();
});

it('rejects malformed and unbounded polling input as redacted validation problems', function (string $query) {
    $this->getJson($this->base.'/changes?'.$query)->assertUnprocessable()->assertJsonPath('status', 422);
})->with(['page[size]=0', 'page[size]=101', 'page[after]=', 'page[after]=not-a-cursor', 'page[after][]=token', 'page[after]='.str_repeat('x', 2049)]);

it('rejects tampered ciphertext without reflecting the cursor in problem details', function () {
    $cursor = ($this->poll)()->assertSuccessful()->json('meta.next_cursor');
    $tampered = substr($cursor, 0, -8).'changed!';
    $response = ($this->poll)($tampered)->assertUnprocessable()->assertJsonPath('errors.0.pointer', '/page/after');
    expect($response->getContent())->not->toContain($tampered, 'MAC', 'payload', 'vendor/');
});

it('validates the decrypted cursor format schema and position before using it', function (string $case) {
    $payload = ['format' => 'liberu.music.session-changes', 'schema_version' => 1, 'workspace_id' => 'studio', 'project_id' => $this->id, 'version' => 1];
    $raw = match ($case) {
        'not-json' => '{',
        'scalar' => 'null',
        'other-purpose' => json_encode(array_replace($payload, ['format' => 'another.stream'])),
        'future-schema' => json_encode(array_replace($payload, ['schema_version' => 2])),
        'negative-position' => json_encode(array_replace($payload, ['version' => -1])),
        'string-position' => json_encode(array_replace($payload, ['version' => '1'])),
        'fractional-position' => json_encode(array_replace($payload, ['version' => 1.5])),
    };
    ($this->poll)(Crypt::encryptString($raw))->assertUnprocessable()->assertJsonPath('errors.0.pointer', '/page/after');
})->with(['not-json', 'scalar', 'other-purpose', 'future-schema', 'negative-position', 'string-position', 'fractional-position']);

it('keeps owned polling queries bounded and avoids loading documents or actor identities', function () {
    DB::enableQueryLog();
    DB::flushQueryLog();
    ($this->poll)(null, 100)->assertSuccessful();
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    expect($queries)->toHaveCount(2)->and(strtolower($queries[1]['query']))->not->toContain('document', 'actor_id', 'select *', 'offset');
});
