<?php

use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Auth\GenericUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Liberu\Music\Projects\MusicProjectsServiceProvider;
use Liberu\Music\ProjectsFilament\Contracts\WorkspaceContext;
use Liberu\Music\ProjectsFilament\MusicProjectsFilamentPlugin;
use Liberu\Music\ProjectsFilament\Pages\Sessions;
use Liberu\Music\ProjectsFilament\Support\FilamentWorkspaceContext;
use Liberu\Music\ProjectsLivewire\Livewire\SessionWorkspace;
use Liberu\Music\ProjectsLivewire\MusicProjectsLivewireServiceProvider;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

beforeEach(function () {
    $this->actor = new GenericUser(['id' => 1]);
    $this->context = Mockery::mock(WorkspaceContext::class);
    $this->context->shouldReceive('current')->andReturnUsing(fn () => config('test.workspace'));
    app()->instance(WorkspaceContext::class, $this->context);
    foreach (['list', 'read', 'write'] as $operation) {
        Gate::define('music.projects.'.$operation, fn ($actor, $workspace) => $actor->id === 1 && $workspace === 'studio');
    }
});

function enableMusicFilamentDependencies(): void
{
    app()->register(MusicProjectsServiceProvider::class);
    app()->register(MusicProjectsLivewireServiceProvider::class);
    $path = dirname((new ReflectionClass(MusicProjectsServiceProvider::class))->getFileName(), 2).'/database/migrations';
    test()->artisan('migrate', ['--path' => $path, '--realpath' => true])->assertSuccessful();
}

it('registers its page through a stable plugin identity', function () {
    $plugin = MusicProjectsFilamentPlugin::make();
    $panel = Panel::make()->id('other');
    $plugin->register($panel);
    $plugin->boot($panel);
    expect($plugin->getId())->toBe('liberu-music-projects')->and($panel->getPages())->toContain(Sessions::class);
});

it('denies guests unavailable dependencies and unselected malformed or unauthorized contexts', function () {
    expect(Sessions::canAccess())->toBeFalse();
    $this->actingAs($this->actor);
    expect(Sessions::canAccess())->toBeFalse();
    app()->register(MusicProjectsServiceProvider::class);
    expect(Sessions::canAccess())->toBeFalse();
    enableMusicFilamentDependencies();
    foreach ([null, '', '../studio', 'other'] as $workspace) {
        config()->set('test.workspace', $workspace);
        expect(Sessions::canAccess())->toBeFalse();
    }
    config()->set('test.workspace', 'studio');
    expect(Sessions::canAccess())->toBeTrue();
    Gate::define('music.projects.list', fn () => false);
    expect(Sessions::canAccess())->toBeFalse();
    Livewire::test(Sessions::class)->assertForbidden();
});

it('composes sessions and reviewed recovery within the current panel workspace', function () {
    enableMusicFilamentDependencies();
    config()->set('test.workspace', 'studio');
    $this->actingAs($this->actor);
    Livewire::test(Sessions::class)->assertSet('workspaceId', 'studio')->assertSee('Save checkpoint')->assertSee('Audio execution is unavailable here')
        ->call('$refresh')->assertSuccessful();
    $component = Livewire::test(SessionWorkspace::class, ['workspace' => 'studio', 'scope' => 'filament'])
        ->set('title', 'Panel session')->set('trackName', 'Piano')->set('trackKind', 'midi')->call('addTrack')->call('save')->assertSet('version', 1)
        ->set('title', 'Autosaved edit')->call('save', true)->assertSet('version', 2)
        ->call('reviewRecovery', 1)->assertSee('Confirm recovery')->call('confirmRecovery')->assertSet('version', 3)->assertSet('title', 'Panel session');
    $id = $component->get('projectId');
    $component->call('exportSaved')->assertFileDownloaded('session-'.$id.'.music.json');
    expect(DB::table('music_project_revisions')->count())->toBe(3);
    $component->set('title', 'Preserved edit');
    config()->set('test.workspace', 'other');
    $component->call('save')->assertSee('Access denied')->assertSet('title', 'Preserved edit')->assertSet('version', 3);
    expect(DB::table('music_project_revisions')->count())->toBe(3);
    config()->set('test.workspace', 'studio');
    $component->call('save')->assertSet('version', 4);
    expect(fn () => $component->set('scope', null))->toThrow(CannotUpdateLockedPropertyException::class);
});

it('rechecks parent hydration and refuses forged or switched workspace identities', function () {
    enableMusicFilamentDependencies();
    config()->set('test.workspace', 'studio');
    $this->actingAs($this->actor);
    $page = Livewire::test(Sessions::class);
    expect(fn () => $page->set('workspaceId', 'other'))->toThrow(CannotUpdateLockedPropertyException::class);
    $page = Livewire::test(Sessions::class);
    config()->set('test.workspace', 'other');
    $page->call('$refresh')->assertForbidden();
});

it('fails closed on unknown embedding scopes and the wrong panel guard actor', function () {
    enableMusicFilamentDependencies();
    config()->set('test.workspace', 'studio');
    $this->actingAs($this->actor);
    Livewire::test(SessionWorkspace::class, ['workspace' => 'studio', 'scope' => 'unknown'])->assertForbidden();
    Filament::getCurrentPanel()->authGuard('panel-test');
    config()->set('auth.guards.panel-test', ['driver' => 'session', 'provider' => 'users']);
    auth('panel-test')->setUser(new GenericUser(['id' => 99]));
    Livewire::test(SessionWorkspace::class, ['workspace' => 'studio', 'scope' => 'filament'])->assertForbidden();
});

it('resolves only the currently selected Filament tenant by default', function () {
    $context = new FilamentWorkspaceContext();
    expect($context->current($this->actor))->toBeNull();
    $tenant = new class() extends Model {};
    $tenant->setRawAttributes(['id' => 42]);
    Filament::setTenant($tenant, isQuiet: true);
    expect($context->current($this->actor))->toBe('42');
});
