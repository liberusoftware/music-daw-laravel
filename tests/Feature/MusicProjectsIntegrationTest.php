<?php

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Liberu\Music\Projects\Commands\SaveSession;
use Liberu\Music\Projects\Data\SessionDocument;
use Liberu\Music\Projects\Services\ProjectSessions;

it('composes Music checkpoints, recovery and portable manifests through the host', function () {
    $actor = User::factory()->create();
    $workspace = 'workspace-'.$actor->getKey();
    Gate::define('music.projects.write', fn (User $user, string $workspaceId) => $user->getKey() === $actor->getKey() && $workspaceId === $workspace);
    Gate::define('music.projects.read', fn (User $user, string $workspaceId) => $user->getKey() === $actor->getKey() && $workspaceId === $workspace);
    $sessions = app(ProjectSessions::class);
    $projectId = (string) Str::uuid();
    $first = $sessions->save($actor, new SaveSession($workspace, $projectId, 'Host session', SessionDocument::empty(), 0, 'create'));
    $draft = $sessions->save($actor, new SaveSession($workspace, $projectId, 'Recovered song', SessionDocument::empty(), 1, 'draft', true));
    $recovered = $sessions->recover($actor, $workspace, $projectId, $draft->version, 2, 'recover');
    $copy = $sessions->import($actor, $workspace, (string) Str::uuid(), $sessions->export($actor, $workspace, $projectId), 'copy');
    expect($first->version)->toBe(1)->and($recovered->autosave)->toBeFalse()->and($recovered->version)->toBe(3)
        ->and($copy->title)->toBe('Recovered song');
    expect(fn () => $sessions->get(User::factory()->create(), $workspace, $projectId))->toThrow(AuthorizationException::class);
});
