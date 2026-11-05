<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Liberu\Music\Projects\Commands\SaveSession;
use Liberu\Music\Projects\Data\SessionDocument;
use Liberu\Music\Projects\Services\ProjectSessions;
use Liberu\Music\ProjectsLivewire\Livewire\SessionWorkspace;
use Liberu\Music\ProjectsLivewire\MusicProjectsLivewireServiceProvider;
use Livewire\Livewire;

beforeEach(function () {
    $this->app->register(MusicProjectsLivewireServiceProvider::class);
    $this->actor = User::factory()->create();
    foreach (['list', 'read', 'write'] as $operation) {
        Gate::define('music.projects.'.$operation, fn ($actor, $workspace) => $actor->is($this->actor) && $workspace === 'studio');
    }
    $this->actingAs($this->actor);
    $this->sessions = app(ProjectSessions::class);
});

it('protects authenticated workspace responses and preserves unsaved edits when access is revoked', function () {
    $response = $this->get('/music/workspaces/studio/sessions')->assertSuccessful()->assertSee('Save checkpoint');
    expect($response->headers->get('Cache-Control'))->toContain('private', 'no-store');
    $component = Livewire::test(SessionWorkspace::class, ['workspace' => 'studio'])->set('title', 'Saved')->call('save');
    $id = $component->get('projectId');
    $component->set('title', 'Unsaved arrangement')->set('trackName', 'Piano')->set('trackKind', 'midi')->call('addTrack');
    $tracks = $component->get('document')['tracks'];
    Gate::define('music.projects.write', fn () => false);
    $component->call('save')->assertSee('Access denied')->assertSet('title', 'Unsaved arrangement')->assertSet('version', 1)
        ->call('newSession')->assertSet('projectId', $id)->assertSet('document.tracks', $tracks)
        ->call('removeTrack', $tracks[0]['id'])->assertSet('document.tracks', $tracks);
    expect($this->sessions->get($this->actor, 'studio', $id)->title)->toBe('Saved')
        ->and(DB::table('music_project_revisions')->count())->toBe(1);
    auth()->forgetGuards();
    $this->get('/music/workspaces/studio/sessions')->assertRedirect(route('login'));
});

it('reviews imports and refuses changed manifests without consuming the current session', function () {
    $component = Livewire::test(SessionWorkspace::class, ['workspace' => 'studio'])->set('title', 'Original')->call('save');
    $id = $component->get('projectId');
    $manifest = $this->sessions->export($this->actor, 'studio', $id);
    $component->set('importManifest', '{')->call('previewImport')->assertSee('not valid JSON')->assertSet('projectId', $id)
        ->call('confirmImport')->assertHasErrors('importManifest')
        ->set('importManifest', $manifest)->call('previewImport')->assertSee('Confirm import')
        ->set('importManifest', $manifest.' ')->call('confirmImport')->assertHasErrors('importManifest');
    expect(DB::table('music_projects')->count())->toBe(1);
    $component->call('cancelReview')->assertSet('reviewedManifest', null)->assertSet('importDestination', null)
        ->set('importManifest', $manifest)->call('previewImport')->call('confirmImport')->assertSet('version', 1);
    expect($component->get('projectId'))->not->toBe($id)->and(DB::table('music_projects')->count())->toBe(2);
    $component->set('title', 'Keep this draft')->call('open', (string) Str::uuid())->assertSee('was not found')->assertSet('title', 'Keep this draft');
});

it('navigates session and revision pages while validating folder moves in a template draft', function () {
    $id = (string) Str::uuid();
    for ($version = 0; $version < 12; $version++) {
        $this->sessions->save($this->actor, new SaveSession('studio', $id, 'History '.$version, SessionDocument::empty(), $version, 'history-'.$version));
    }
    for ($index = 0; $index < 11; $index++) {
        $this->sessions->save($this->actor, new SaveSession('studio', (string) Str::uuid(), 'Session '.$index, SessionDocument::empty(), 0, 'initial'));
    }
    $component = Livewire::test(SessionWorkspace::class, ['workspace' => 'studio', 'project' => $id])
        ->assertSee('Review revision 12')->call('olderHistory')->assertSee('Review revision 1')->assertDontSee('Review revision 12')
        ->call('firstHistory')->assertSee('Review revision 12')->call('nextPage')->assertSee('Session 0')->call('firstPage')->assertSee('Session 10')
        ->call('newSession', true)->assertSet('template', true)->set('trackName', 'Keys')->set('trackKind', 'folder')->call('addTrack');
    $folder = $component->get('document')['tracks'][0]['id'];
    $component->set('trackName', 'Synth')->set('trackKind', 'midi')->set('parentTrackId', $folder)->call('addTrack');
    $child = $component->get('document')['tracks'][1]['id'];
    $component->call('removeTrack', $folder)->assertHasErrors('tracks')->call('moveTrack', $folder, $folder)->assertHasErrors('tracks')
        ->call('moveTrack', $child, '')->assertSet('document.tracks.1.parent_id', null)->call('removeTrack', $folder)->call('save')->assertSet('version', 1);
    $saved = $this->sessions->get($this->actor, 'studio', $component->get('projectId'));
    expect($saved->template)->toBeTrue()->and($saved->document->jsonSerialize()['tracks'])->toHaveCount(1)
        ->and($saved->document->jsonSerialize()['tracks'][0]['id'])->toBe($child);
});

it('restores opaque native state through the host without allowing document edits to erase locked blocks', function () {
    $id = (string) Str::uuid();
    $document = SessionDocument::empty()->jsonSerialize();
    $document['modules'] = ['unavailable-synth' => ['schema_version' => 4, 'state' => ['preset_id' => PHP_INT_MAX]]];
    $this->sessions->save($this->actor, new SaveSession('studio', $id, 'Original preset', SessionDocument::fromArray($document), 0, 'initial'));
    $component = Livewire::test(SessionWorkspace::class, ['workspace' => 'studio', 'project' => $id]);
    $backup = ['format' => 'liberu.music.draft', 'schema_version' => 2, 'workspace_id' => 'studio', 'project_id' => $id,
        'version' => 1, 'template' => false, 'title' => 'Offline preset', 'document' => SessionDocument::empty()->jsonSerialize(),
        'command_key' => $component->get('commandKey'), 'assets_json' => '[]', 'modules_json' => $component->get('preservedModulesJson')];
    $component->call('newSession')->call('restoreDraft', json_encode($backup, JSON_THROW_ON_ERROR))->assertSet('version', 1)
        ->set('document.modules', [])->call('save')->assertSet('version', 2);
    expect($this->sessions->get($this->actor, 'studio', $id)->document->jsonSerialize()['modules'])->toBe($document['modules']);
    $backup['schema_version'] = 1;
    unset($backup['assets_json'], $backup['modules_json']);
    $component->call('restoreDraft', json_encode($backup, JSON_THROW_ON_ERROR))->assertSet('version', 1)
        ->assertSet('document.modules', $document['modules'])->call('save')->assertSet('version', 2);
    // An unchanged restored command replays its original receipt; changed content must conflict.
    $component->call('restoreDraft', json_encode($backup, JSON_THROW_ON_ERROR))->set('title', 'Changed offline preset')
        ->call('save')->assertSee('Your draft is preserved. Back it up');
    $backup['workspace_id'] = 'other';
    $component->call('restoreDraft', json_encode($backup, JSON_THROW_ON_ERROR))->assertHasErrors('draft.workspace_id')
        ->assertSet('title', 'Changed offline preset')->call('restoreDraft', '{')->assertSee('not valid JSON');
    expect(DB::table('music_project_revisions')->count())->toBe(2);
});
