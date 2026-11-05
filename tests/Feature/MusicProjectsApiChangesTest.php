<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Liberu\Music\Projects\Data\SessionDocument;
use Liberu\Music\ProjectsApi\MusicProjectsApiServiceProvider;

it('resynchronizes a scoped API revision feed after interruption and requires explicit recovery for a missing snapshot', function () {
    $this->app->register(MusicProjectsApiServiceProvider::class);
    app('router')->getRoutes()->refreshNameLookups();
    $actor = User::factory()->create();
    foreach (['read', 'write'] as $operation) {
        Gate::define('music.projects.'.$operation, fn ($user, $workspace) => $user->is($actor) && $workspace === 'studio');
    }
    Sanctum::actingAs($actor, ['music.projects.read', 'music.projects.write']);
    $id = (string) Str::uuid();
    $base = '/api/v1/music-projects/workspaces/studio/sessions/'.$id;
    $payload = ['title' => 'Connected session', 'document' => SessionDocument::empty()->jsonSerialize()];
    $this->postJson($base.'/checkpoints', $payload, ['If-Match' => '"0"', 'Idempotency-Key' => 'create'])->assertCreated();
    $snapshot = $this->getJson($base)->assertSuccessful()->assertJsonPath('data.version', 1);
    $cursor = $snapshot->json('meta.changes_cursor');
    $this->postJson($base.'/checkpoints', $payload + ['autosave' => true], ['If-Match' => '"1"', 'Idempotency-Key' => 'draft'])->assertCreated();
    $query = http_build_query(['page' => ['after' => $cursor]]);
    $updates = $this->getJson($base.'/changes?'.$query)->assertSuccessful()->assertJsonPath('data.resync_required', false);
    expect(array_column($updates->json('data.changes'), 'version'))->toBe([2]);
    DB::table('music_project_revisions')->where('project_id', $id)->where('version', 2)->delete();
    $gap = $this->getJson($base.'/changes?'.$query)->assertSuccessful()->assertJsonPath('data.resync_required', true)->assertJsonPath('meta.next_cursor', null);
    $this->getJson($gap->json('links.snapshot'))->assertNotFound();
    $this->postJson($base.'/recoveries', ['source_version' => 1], ['If-Match' => '"2"', 'Idempotency-Key' => 'recover'])->assertCreated()->assertJsonPath('data.version', 3);
    $recovered = $this->getJson($base)->assertSuccessful()->assertJsonPath('data.version', 3);
    $query = http_build_query(['page' => ['after' => $recovered->json('meta.changes_cursor')]]);
    $this->getJson($base.'/changes?'.$query)->assertSuccessful()->assertJsonPath('data.changes', [])->assertJsonPath('data.resync_required', false);
    Gate::define('music.projects.read', fn () => false);
    $this->getJson($base.'/changes?'.$query)->assertForbidden();
});
