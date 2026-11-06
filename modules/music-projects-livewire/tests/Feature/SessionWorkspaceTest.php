<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Liberu\Foundation\Files\Contracts\MediaAccess;
use Liberu\Music\Projects\Commands\SaveSession;
use Liberu\Music\Projects\Data\SessionDocument;
use Liberu\Music\Projects\MusicProjectsServiceProvider;
use Liberu\Music\Projects\Services\ProjectSessions;
use Liberu\Music\ProjectsLivewire\Contracts\WorkspaceScope;
use Liberu\Music\ProjectsLivewire\Livewire\SessionWorkspace;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

beforeEach(function () {
    $this->app->register(MusicProjectsServiceProvider::class);
    $path = dirname((new ReflectionClass(MusicProjectsServiceProvider::class))->getFileName(), 2).'/database/migrations';
    $this->artisan('migrate', ['--path' => $path, '--realpath' => true])->assertSuccessful();
    $this->actor = new GenericUser(['id' => 1]);
    foreach (['list', 'read', 'write'] as $operation) {
        Gate::define('music.projects.'.$operation, fn ($actor, $workspace) => $actor->id === 1 && $workspace === 'studio');
    }
    $this->actingAs($this->actor);
    $this->workspace = fn (?string $project = null) => Livewire::test(SessionWorkspace::class, ['workspace' => 'studio', 'project' => $project]);
});

it('creates tracks folders checkpoints autosaves and a reviewed recovery without rewriting history', function () {
    $component = ($this->workspace)()->assertSee('Audio execution is unavailable here')->assertSee('No tracks yet')
        ->set('title', 'My session')->set('trackKind', 'folder')->set('trackName', 'Keys')->set('parentTrackId', '')->call('addTrack')
        ->assertSet('notice', 'Track added to this draft. Save a checkpoint to keep it.');
    $folder = $component->get('document')['tracks'][0]['id'];
    $component->set('trackKind', 'midi')->set('trackName', 'Piano')->set('parentTrackId', $folder)->call('addTrack')->call('save');
    $id = $component->get('projectId');
    $component->assertSet('version', 1)->assertSet('notice', 'Checkpoint confirmed by the server.');
    $component->set('title', 'Autosaved edit')->call('save', true)->assertSet('version', 2)
        ->call('reviewRecovery', 1)->assertSet('recoveryVersion', 1)->assertSee('Confirm recovery')->call('confirmRecovery')
        ->assertSet('version', 3)->assertSet('title', 'My session')->assertSet('recoveryVersion', null);
    $saved = app(ProjectSessions::class)->get($this->actor, 'studio', $id);
    expect($saved->document->jsonSerialize()['tracks'][1]['parent_id'])->toBe($folder)
        ->and($saved->autosave)->toBeFalse()->and(DB::table('music_project_revisions')->count())->toBe(3);
    ($this->workspace)($id)->assertSet('title', 'My session')->assertSet('version', 3)->assertSee('Autosave');
});

it('supports template drafts and bounded session navigation', function () {
    for ($index = 0; $index < 11; $index++) {
        app(ProjectSessions::class)->save($this->actor, new SaveSession('studio', (string) Str::uuid(), 'Song '.$index, SessionDocument::empty(), 0, 'create'));
    }
    $component = ($this->workspace)()->assertSee('Song 10');
    expect($component->get('nextCursor'))->toBeInt();
    $component->call('nextPage')->assertSee('Song 0')->assertDontSee('Song 10')->call('firstPage')->assertSee('Song 10')
        ->call('newSession', true)->assertSet('template', true)->set('title', 'Starter')->call('save')->assertSet('version', 1)
        ->call('newSession')->assertSet('version', 0)->assertSet('template', false)->assertSet('title', 'Untitled session');
});

it('reviews exports and imports portable sessions into a separate authorized identity', function () {
    $component = ($this->workspace)()->set('title', 'Portable')->call('save');
    $source = $component->get('projectId');
    $component->call('exportSaved')->assertFileDownloaded('session-'.$source.'.music.json');
    $manifest = app(ProjectSessions::class)->export($this->actor, 'studio', $source);
    $component->set('importManifest', $manifest)->call('previewImport')->assertSee('Confirm import');
    expect($component->get('importPreview')['title'])->toBe('Portable')->and(DB::table('music_projects')->count())->toBe(1);
    $component->call('confirmImport')->assertSet('notice', 'Portable session imported and opened.')->assertSet('importPreview', null);
    expect($component->get('projectId'))->not->toBe($source)->and(DB::table('music_projects')->count())->toBe(2);
});

it('requires a fresh import review and explicit recovery selection', function () {
    $component = ($this->workspace)()->call('confirmImport')->assertHasErrors('importManifest')->call('confirmRecovery')->assertHasErrors('recovery');
    $component->set('title', 'Source')->call('save');
    $manifest = app(ProjectSessions::class)->export($this->actor, 'studio', $component->get('projectId'));
    $component->set('importManifest', $manifest)->call('previewImport')->set('importManifest', $manifest.' ')->call('confirmImport')->assertHasErrors('importManifest');
    expect(DB::table('music_projects')->count())->toBe(1);
    $component->call('cancelReview')->assertSet('importPreview', null)->assertSet('reviewedManifest', null);
});

it('keeps drafts on conflicts validation errors and permission revocation', function () {
    $component = ($this->workspace)()->set('title', 'Original')->call('save');
    $id = $component->get('projectId');
    app(ProjectSessions::class)->save($this->actor, new SaveSession('studio', $id, 'Concurrent edit', SessionDocument::empty(), 1, 'concurrent'));
    $component->set('title', 'Keep my edit')->call('save')->assertSet('version', 1)->assertSet('title', 'Keep my edit')
        ->assertSee('Your draft is preserved. Back it up');
    $component->set('trackName', '')->call('addTrack')->assertHasErrors('name')->assertSet('title', 'Keep my edit');
    Gate::define('music.projects.write', fn () => false);
    $component->call('newSession')->assertSet('title', 'Keep my edit')->assertSet('problem', 'Access denied. No changes were saved.');
    expect(app(ProjectSessions::class)->get($this->actor, 'studio', $id)->title)->toBe('Concurrent edit');
});

it('preserves a draft on missing sessions malformed JSON and failed dependencies', function () {
    $component = ($this->workspace)()->set('title', 'Keep it')->call('open', (string) Str::uuid())->assertSet('title', 'Keep it')
        ->assertSee('was not found')->set('importManifest', '{')->call('previewImport')->assertSee('not valid JSON');
    $input = SessionDocument::empty()->jsonSerialize();
    $input['assets'] = [['id' => 'missing-media', 'sha256' => str_repeat('a', 64), 'sample_rate' => 48000, 'channels' => 1, 'frames' => 2]];
    // The missing dependency must fail safely, without showing internal exception details.
    app()->instance(MediaAccess::class, Mockery::mock(MediaAccess::class)->shouldReceive('authorized')->andReturnTrue()->getMock());
    $component->set('document', $input)->call('save')->assertSee('Restore missing media or dependencies')->assertSet('title', 'Keep it');
    expect(DB::table('music_projects')->count())->toBe(0);
});

it('restores versioned draft backups without saving or silently rebasing a stale base', function () {
    $component = ($this->workspace)()->set('title', 'Saved')->call('save');
    $draft = ['format' => 'liberu.music.draft', 'schema_version' => 1, 'workspace_id' => 'studio', 'project_id' => $component->get('projectId'),
        'version' => 1, 'template' => false, 'title' => 'Offline edit', 'document' => $component->get('document'), 'command_key' => $component->get('commandKey')];
    app(ProjectSessions::class)->save($this->actor, new SaveSession('studio', $draft['project_id'], 'New server edit', SessionDocument::empty(), 1, 'concurrent'));
    $component->call('newSession')->set('parentTrackId', (string) Str::uuid())->call('restoreDraft', json_encode($draft))->assertSet('parentTrackId', null)->assertSet('historyBefore', null)->assertSet('version', 1)->assertSet('title', 'Offline edit')->call('save')->assertSee('Your draft is preserved. Back it up');
    expect(DB::table('music_project_revisions')->count())->toBe(2);
    $draft['project_id'] = (string) Str::uuid();
    $draft['version'] = 0;
    $component->call('restoreDraft', json_encode($draft))->call('save')->assertSet('version', 1);
    expect(DB::table('music_projects')->count())->toBe(2);
});

it('rejects invalid cross-workspace and oversized draft backups before changing the draft', function () {
    $component = ($this->workspace)()->set('title', 'Protected');
    $draft = ['format' => 'liberu.music.draft', 'schema_version' => 1, 'workspace_id' => 'other', 'project_id' => $component->get('projectId'),
        'version' => 0, 'template' => false, 'title' => 'Wrong workspace', 'document' => $component->get('document'), 'command_key' => $component->get('commandKey')];
    $component->call('restoreDraft', json_encode($draft))->assertHasErrors('draft.workspace_id')->assertSet('title', 'Protected');
    $component->call('restoreDraft', '{')->assertSee('not valid JSON')->call('restoreDraft', '{}')->assertHasErrors()
        ->call('restoreDraft', str_repeat(' ', 64 * 1024 * 1024 + 1))->assertHasErrors('draft')->assertSet('title', 'Protected');
});

it('locks identity revision and replay state against browser property tampering', function ($property, $value) {
    expect(fn () => ($this->workspace)()->set($property, $value))->toThrow(CannotUpdateLockedPropertyException::class);
})->with([['workspaceId', 'other'], ['projectId', 'forged'], ['version', 99], ['commandKey', 'forged'], ['reviewedManifest', 'forged'], ['preservedAssetsJson', 'forged'], ['preservedModulesJson', 'forged']]);

it('renders the full page route and redirects guests to the hosts login', function () {
    $response = $this->get('/music/workspaces/studio/sessions')->assertSuccessful()->assertSee('Sessions · Music')->assertSee('Save checkpoint');
    expect($response->headers->get('Cache-Control'))->toContain('no-store', 'private');
    auth()->forgetGuards();
    Route::get('/login', fn () => 'Login')->name('login');
    app('router')->getRoutes()->refreshNameLookups();
    $this->get('/music/workspaces/studio/sessions')->assertRedirect('/login');
});

it('does not disclose another workspaces metadata to an authenticated actor', function () {
    Livewire::test(SessionWorkspace::class, ['workspace' => 'other'])->assertSee('Access denied');
});

it('paginates older revision history and clears stale parent selections when opening a new draft', function () {
    $id = (string) Str::uuid();
    for ($version = 0; $version < 12; $version++) {
        app(ProjectSessions::class)->save($this->actor, new SaveSession('studio', $id, 'Version '.$version, SessionDocument::empty(), $version, 'save-'.$version));
    }
    $component = ($this->workspace)($id)->assertSee('Review revision 12')->call('olderHistory')->assertSee('Review revision 1')->assertDontSee('Review revision 12')
        ->call('firstHistory')->assertSee('Review revision 12')->set('parentTrackId', (string) Str::uuid())->call('newSession')
        ->assertSet('parentTrackId', null)->assertSet('historyBefore', null)->assertSet('historyNext', null);
});

it('moves and removes draft tracks through core graph validation without dropping preserved state', function () {
    $component = ($this->workspace)()->set('trackName', 'Folder')->set('trackKind', 'folder')->call('addTrack');
    $folderId = $component->get('document')['tracks'][0]['id'];
    $component->set('trackName', 'Child')->set('trackKind', 'midi')->set('parentTrackId', $folderId)->call('addTrack');
    $childId = $component->get('document')['tracks'][1]['id'];
    $component->call('removeTrack', $folderId)->assertHasErrors('tracks');
    expect($component->get('document')['tracks'])->toHaveCount(2);
    $component->call('moveTrack', $folderId, $folderId)->assertHasErrors('tracks')
        ->call('moveTrack', $childId, '')->assertSet('document.tracks.1.parent_id', null)
        ->call('removeTrack', $folderId);
    expect(array_column($component->get('document')['tracks'], 'id'))->toBe([$childId]);
    $component->call('moveTrack', 'missing')->assertHasErrors('tracks')->call('removeTrack', 'missing')->assertHasErrors('tracks');
    Gate::define('music.projects.write', fn () => false);
    $component->call('removeTrack', $childId)->assertSee('Access denied');
    expect($component->get('document')['tracks'])->toHaveCount(1);
});

it('preserves opaque 64-bit state in version two backups and recovers legacy blocks from their saved base', function () {
    $id = (string) Str::uuid();
    $input = SessionDocument::empty()->jsonSerialize();
    $input['modules'] = ['unavailable-instrument' => ['schema_version' => 7, 'state' => ['identifier' => PHP_INT_MAX]]];
    app(ProjectSessions::class)->save($this->actor, new SaveSession('studio', $id, 'Opaque state', SessionDocument::fromArray($input), 0, 'initial'));
    $component = ($this->workspace)($id);
    $draft = ['format' => 'liberu.music.draft', 'schema_version' => 2, 'workspace_id' => 'studio', 'project_id' => $id,
        'version' => 1, 'template' => false, 'title' => 'Restored state', 'document' => SessionDocument::empty()->jsonSerialize(),
        'command_key' => $component->get('commandKey'), 'assets_json' => '[]', 'modules_json' => $component->get('preservedModulesJson')];
    $component->call('newSession')->call('restoreDraft', json_encode($draft))->call('save')->assertSet('version', 2);
    expect(app(ProjectSessions::class)->get($this->actor, 'studio', $id)->document->jsonSerialize()['modules'])->toBe($input['modules']);
    $draft['schema_version'] = 1;
    unset($draft['assets_json'], $draft['modules_json']);
    $component->call('restoreDraft', json_encode($draft));
    expect($component->get('document')['modules'])->toBe($input['modules']);
    $draft['schema_version'] = 2;
    $component->call('restoreDraft', json_encode($draft))->assertHasErrors('draft.assets_json');
    $draft['assets_json'] = '[]';
    $draft['modules_json'] = '{';
    $component->call('restoreDraft', json_encode($draft))->assertSee('not valid JSON');
});

it('denies an embedding scope without its owning adapter and locks the selected scope', function () {
    Livewire::test(SessionWorkspace::class, ['workspace' => 'studio', 'scope' => 'unknown'])->assertForbidden();
    expect(fn () => ($this->workspace)()->set('scope', 'forged'))->toThrow(CannotUpdateLockedPropertyException::class);
});

it('rechecks its embedding boundary before modifying a draft after the host context changes', function () {
    $boundary = Mockery::mock(WorkspaceScope::class);
    $boundary->shouldReceive('authorize')->andReturnUsing(function ($actor, $workspace, $scope) {
        if ($scope !== 'panel' || config('test.current_workspace') !== $workspace) {
            throw new AuthorizationException();
        }
    });
    app()->instance(WorkspaceScope::class, $boundary);
    config()->set('test.current_workspace', 'studio');
    $component = Livewire::test(SessionWorkspace::class, ['workspace' => 'studio', 'scope' => 'panel'])
        ->set('title', 'Keep this draft')->call('save')->assertSet('version', 1);
    config()->set('test.current_workspace', 'other');
    $component->set('title', 'Unsaved change')->call('save')->assertSet('version', 1)->assertSee('Access denied')
        ->call('newSession')->assertSet('title', 'Unsaved change');
    expect(DB::table('music_project_revisions')->count())->toBe(1);
});
