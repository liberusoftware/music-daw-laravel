<?php

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Liberu\Music\Projects\Commands\SaveSession;
use Liberu\Music\Projects\Data\SessionDocument;
use Liberu\Music\Projects\Services\ProjectSessions;
use Liberu\Music\Projects\Services\SessionChanges;

it('resumes durable session notifications through the host and denies a revoked reconnect', function () {
    $actor = User::factory()->create();
    foreach (['read', 'write'] as $operation) {
        Gate::define('music.projects.'.$operation, fn ($user, $workspace) => $user->is($actor) && $workspace === 'studio');
    }
    $id = (string) Str::uuid();
    $sessions = app(ProjectSessions::class);
    $sessions->save($actor, new SaveSession('studio', $id, 'Song', SessionDocument::empty(), 0, 'first'));
    $changes = app(SessionChanges::class);
    $page = $changes->since($actor, 'studio', $id);
    $sessions->save($actor, new SaveSession('studio', $id, 'Draft', SessionDocument::empty(), 1, 'draft', true));
    $resumed = $changes->since($actor, 'studio', $id, $page->nextCursor);
    expect(array_column($resumed->changes, 'version'))->toBe([2])
        ->and($resumed->changes[0]['autosave'])->toBeTrue()->and($resumed->resyncRequired)->toBeFalse();
    Gate::define('music.projects.read', fn () => false);
    expect(fn () => $changes->since($actor, 'studio', $id, $resumed->nextCursor))->toThrow(AuthorizationException::class);
});

it('requires explicit snapshot resync for cursors ahead of the server and gaps inside a revision page', function () {
    $actor = User::factory()->create();
    foreach (['read', 'write'] as $operation) {
        Gate::define('music.projects.'.$operation, fn ($user, $workspace) => $user->is($actor) && $workspace === 'studio');
    }
    $id = (string) Str::uuid();
    $sessions = app(ProjectSessions::class);
    for ($version = 0; $version < 3; $version++) {
        $sessions->save($actor, new SaveSession('studio', $id, 'Song '.$version, SessionDocument::empty(), $version, 'save-'.$version));
    }
    $changes = app(SessionChanges::class);
    $future = $changes->since($actor, 'studio', $id, 4);
    expect($future->jsonSerialize())->toBe(['schema_version' => 1, 'changes' => [], 'next_cursor' => 4,
        'latest_version' => 3, 'has_more' => false, 'resync_required' => true]);
    DB::table('music_project_revisions')->where('project_id', $id)->where('version', 2)->delete();
    $gap = $changes->since($actor, 'studio', $id, limit: 3);
    expect($gap->jsonSerialize())->toBe(['schema_version' => 1, 'changes' => [], 'next_cursor' => 0,
        'latest_version' => 3, 'has_more' => false, 'resync_required' => true]);
    // A fresh authorized snapshot at version 3 can resume beyond the old gap.
    $snapshot = $sessions->get($actor, 'studio', $id);
    expect($changes->since($actor, 'studio', $id, $snapshot->version)->resyncRequired)->toBeFalse();
});
