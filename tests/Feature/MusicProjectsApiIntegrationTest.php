<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Liberu\Music\Projects\Commands\SaveSession;
use Liberu\Music\Projects\Data\SessionDocument;
use Liberu\Music\Projects\Services\ProjectSessions;
use Liberu\Music\ProjectsApi\MusicProjectsApiServiceProvider;

it('keeps installed Projects API routes disabled until explicitly enabled', function () {
    expect(Route::has('music.projects.api.show'))->toBeFalse();
});

it('composes authorized API reads and import preview over the host session owner', function () {
    $this->app->register(MusicProjectsApiServiceProvider::class);
    $actor = User::factory()->create();
    foreach (['list', 'read', 'write'] as $operation) {
        Gate::define('music.projects.'.$operation, fn ($user, $workspace) => $user->is($actor) && $workspace === 'studio');
    }
    $id = (string) Str::uuid();
    app(ProjectSessions::class)->save($actor, new SaveSession('studio', $id, 'Host API song', SessionDocument::empty(), 0, 'create'));
    $base = '/api/v1/music-projects/workspaces/studio';
    $this->getJson($base.'/capabilities')->assertUnauthorized()->assertJsonPath('status', 401);
    Sanctum::actingAs($actor, ['music.projects.read', 'music.projects.preview']);
    $this->getJson($base.'/sessions/'.$id)->assertSuccessful()->assertJsonPath('data.title', 'Host API song')->assertHeader('ETag', '"1"');
    $manifest = $this->getJson($base.'/sessions/'.$id.'/manifest')->assertSuccessful()->json('data');
    $destination = (string) Str::uuid();
    $this->postJson($base.'/sessions/'.$destination.'/import-preview', ['manifest' => json_encode($manifest)])
        ->assertSuccessful()->assertJsonPath('data.asset_count', 0);
    expect(DB::table('music_projects')->where('id', $destination)->exists())->toBeFalse();
    Gate::define('music.projects.read', fn () => false);
    $this->getJson($base.'/sessions/'.$id)->assertForbidden()->assertHeader('Content-Type', 'application/problem+json');
});
