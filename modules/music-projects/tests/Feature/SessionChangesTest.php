<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Database\RecordNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Liberu\Music\Projects\Commands\SaveSession;
use Liberu\Music\Projects\Data\SessionDocument;
use Liberu\Music\Projects\Services\ProjectSessions;
use Liberu\Music\Projects\Services\SessionChanges;

beforeEach(function () {
    $this->artisan('migrate', ['--path' => realpath(__DIR__.'/../../database/migrations'), '--realpath' => true])->assertSuccessful();
    $this->actor = new GenericUser(['id' => 1]);
    $this->projectId = (string) Str::uuid();
    foreach (['read', 'write'] as $operation) {
        app(Gate::class)->define('music.projects.'.$operation, fn ($actor, $workspace) => $actor->id === 1 && in_array($workspace, ['studio', 'other']));
    }
    $this->sessions = app(ProjectSessions::class);
    $this->changes = app(SessionChanges::class);
    $this->save = fn (int $version, bool $autosave = false) => $this->sessions->save($this->actor, new SaveSession('studio', $this->projectId, 'Song', SessionDocument::empty(), $version, 'save-'.$version, $autosave));
});

it('reconnects to a bounded ordered feed including autosaves without replaying acknowledged revisions', function () {
    ($this->save)(0);
    ($this->save)(1, true);
    ($this->save)(2);
    $page = $this->changes->since($this->actor, 'studio', $this->projectId, 0, 2);
    expect(array_column($page->changes, 'version'))->toBe([1, 2])
        ->and(array_column($page->changes, 'autosave'))->toBe([false, true])
        ->and($page->hasMore)->toBeTrue()->and($page->resyncRequired)->toBeFalse()
        ->and($page->nextCursor)->toBe(2)->and($page->latestVersion)->toBe(3);
    expect($page->jsonSerialize())->toMatchArray(['schema_version' => 1, 'next_cursor' => 2, 'latest_version' => 3, 'has_more' => true, 'resync_required' => false]);
    expect($page->changes[0])->toBe([
        'version' => 1, 'autosave' => false, 'template' => false, 'checksum' => SessionDocument::empty()->checksum(),
        'created_at' => DB::table('music_project_revisions')->where('version', 1)->value('created_at'),
    ]);
    ($this->save)(3);
    $next = $this->changes->since($this->actor, 'studio', $this->projectId, $page->nextCursor, 2);
    expect(array_column($next->changes, 'version'))->toBe([3, 4])->and($next->hasMore)->toBeFalse()->and($next->nextCursor)->toBe(4);
    $idle = $this->changes->since($this->actor, 'studio', $this->projectId, $next->nextCursor);
    expect($idle->changes)->toBe([])->and($idle->nextCursor)->toBe(4)->and($idle->resyncRequired)->toBeFalse();
    // Repeating the same saved command cannot create a duplicate notification.
    ($this->save)(3);
    expect($this->changes->since($this->actor, 'studio', $this->projectId, 3)->changes)->toHaveCount(1);
});

it('requires resynchronization after a missing revision or a cursor ahead of authoritative state', function () {
    ($this->save)(0);
    ($this->save)(1);
    ($this->save)(2);
    $ahead = $this->changes->since($this->actor, 'studio', $this->projectId, 10);
    expect($ahead->resyncRequired)->toBeTrue()->and($ahead->changes)->toBe([])->and($ahead->latestVersion)->toBe(3)->and($ahead->nextCursor)->toBe(10);
    DB::table('music_project_revisions')->where('version', 2)->delete();
    $gap = $this->changes->since($this->actor, 'studio', $this->projectId);
    expect($gap->resyncRequired)->toBeTrue()->and($gap->changes)->toBe([])->and($gap->nextCursor)->toBe(0);
    DB::table('music_project_revisions')->where('version', 3)->delete();
    $tail = $this->changes->since($this->actor, 'studio', $this->projectId, 1);
    expect($tail->resyncRequired)->toBeTrue()->and($tail->changes)->toBe([])->and($tail->hasMore)->toBeFalse();
});

it('rechecks read policy on every poll and cannot expose a project through another workspace', function () {
    ($this->save)(0);
    expect(fn () => $this->changes->since(new GenericUser(['id' => 2]), 'studio', $this->projectId))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->changes->since($this->actor, 'other', $this->projectId))->toThrow(RecordNotFoundException::class)
        ->and(fn () => $this->changes->since($this->actor, 'studio', (string) Str::uuid()))->toThrow(RecordNotFoundException::class);
    app(Gate::class)->define('music.projects.read', fn () => false);
    expect(fn () => $this->changes->since($this->actor, 'studio', $this->projectId, 1))->toThrow(AuthorizationException::class);
});

it('rejects invalid reconnect cursors and unbounded pages', function (int $after, int $limit) {
    expect(fn () => $this->changes->since($this->actor, 'studio', $this->projectId, $after, $limit))->toThrow(ValidationException::class);
})->with([[-1, 25], [0, 0], [0, 101]]);

it('reads only notification columns in two queries as revision history grows', function () {
    for ($version = 0; $version < 30; $version++) {
        ($this->save)($version);
    }
    DB::enableQueryLog();
    DB::flushQueryLog();
    $page = $this->changes->since($this->actor, 'studio', $this->projectId, 0, 25);
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    expect($queries)->toHaveCount(2)->and($page->changes)->toHaveCount(25)->and($page->hasMore)->toBeTrue()
        ->and(strtolower($queries[1]['query']))->not->toContain('document', 'select *', 'offset');
});

it('defers revisions committed after the page horizon to the next reconnect', function () {
    ($this->save)(0);
    $committed = false;
    DB::listen(function ($query) use (&$committed) {
        if (! $committed && str_contains($query->sql, 'select "version" from "music_projects"')) {
            $committed = true;
            ($this->save)(1);
        }
    });
    $page = $this->changes->since($this->actor, 'studio', $this->projectId);
    expect($committed)->toBeTrue()->and($page->latestVersion)->toBe(1)
        ->and(array_column($page->changes, 'version'))->toBe([1])->and($page->resyncRequired)->toBeFalse();
    $next = $this->changes->since($this->actor, 'studio', $this->projectId, $page->nextCursor);
    expect(array_column($next->changes, 'version'))->toBe([2])->and($next->latestVersion)->toBe(2);
});
