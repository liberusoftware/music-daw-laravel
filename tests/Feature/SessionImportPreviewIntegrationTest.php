<?php

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Liberu\Music\Projects\Commands\SaveSession;
use Liberu\Music\Projects\Data\SessionDocument;
use Liberu\Music\Projects\Services\ProjectSessions;

it('previews an exported backup without mutating the destination and rechecks access on confirmation', function () {
    $actor = User::factory()->create();
    foreach (['read', 'write'] as $operation) {
        Gate::define('music.projects.'.$operation, fn ($user, $workspace) => $user->is($actor) && $workspace === 'studio');
    }
    $sessions = app(ProjectSessions::class);
    $source = (string) Str::uuid();
    $destination = (string) Str::uuid();
    $sessions->save($actor, new SaveSession('studio', $source, 'Backup', SessionDocument::empty(), 0, 'create'));
    $manifest = $sessions->export($actor, 'studio', $source);
    $preview = $sessions->previewImport($actor, 'studio', $destination, $manifest);
    expect($preview->jsonSerialize())->toMatchArray(['title' => 'Backup', 'document_schema_version' => 1, 'track_count' => 0, 'asset_count' => 0])
        ->and(DB::table('music_projects')->where('id', $destination)->exists())->toBeFalse()
        ->and(DB::table('music_project_revisions')->count())->toBe(1);
    Gate::define('music.projects.write', fn () => false);
    expect(fn () => $sessions->import($actor, 'studio', $destination, $manifest, 'confirm'))->toThrow(AuthorizationException::class);
    expect(DB::table('music_project_revisions')->count())->toBe(1);
});
