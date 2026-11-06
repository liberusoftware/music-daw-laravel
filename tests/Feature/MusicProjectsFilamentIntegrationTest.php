<?php

use App\Filament\ModulePlugins;
use App\Models\User;
use App\Providers\MusicModuleIntegrationProvider;
use App\Support\MusicWorkspaceContext;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Liberu\Foundation\Files\Contracts\MalwareScanner;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\Foundation\Tenancy\TenantContext;
use Liberu\Music\MediaLibrary\Commands\StartUpload;
use Liberu\Music\MediaLibrary\MusicMediaLibraryServiceProvider;
use Liberu\Music\MediaLibrary\Services\MediaUploads;
use Liberu\Music\Projects\Data\SessionDocument;
use Liberu\Music\Projects\Services\ProjectSessions;
use Liberu\Music\ProjectsFilament\Contracts\WorkspaceContext;
use Liberu\Music\ProjectsFilament\MusicProjectsFilamentPlugin;
use Liberu\Music\ProjectsFilament\MusicProjectsFilamentServiceProvider;
use Liberu\Music\ProjectsFilament\Pages\Sessions;
use Liberu\Music\ProjectsLivewire\Livewire\SessionWorkspace;
use Liberu\Music\ProjectsLivewire\MusicProjectsLivewireServiceProvider;
use Livewire\Livewire;

it('leaves the installed Filament sessions plugin disabled until explicitly enabled', function () {
    expect(collect(app(ModulePlugins::class)->forPanel('app'))->map->getId()->all())->not->toContain('liberu-music-projects');
});

it('resolves the host workspace through foundation context and the actual actors current team', function () {
    $actor = User::factory()->create();
    $context = app(WorkspaceContext::class);
    expect($context)->toBeInstanceOf(MusicWorkspaceContext::class)->and($context->current($actor))->toBeNull();
    $team = Team::factory()->create(['user_id' => $actor->id]);
    $actor->forceFill(['current_team_id' => $team->id])->save();
    $actor->unsetRelation('currentTeam');
    expect($context->current($actor))->toBe((string) $team->id);
    $otherTeam = Team::factory()->create(['user_id' => $actor->id]);
    TenantContext::set($otherTeam->id);
    expect($context->current($actor))->toBe((string) $otherTeam->id);
    TenantContext::clear();
});

it('embeds the installed session journey with verified audio and rejects writes after a team switch', function () {
    $this->app->register(MusicProjectsLivewireServiceProvider::class);
    $this->app->register(MusicProjectsFilamentServiceProvider::class);
    $this->app->register(MusicMediaLibraryServiceProvider::class);
    (new MusicModuleIntegrationProvider($this->app))->boot();
    $this->artisan('migrate', ['--path' => base_path('modules/music-media-library/database/migrations'), '--realpath' => true])->assertSuccessful();
    config()->set('music-media.disk', 'filament-session-audio');
    Storage::fake('filament-session-audio');
    $actor = User::factory()->create();
    $team = Team::factory()->create(['user_id' => $actor->id]);
    $actor->forceFill(['current_team_id' => $team->id])->save();
    $workspace = (string) $team->id;
    foreach (['music.projects.list', 'music.projects.read', 'music.projects.write', 'music.media.upload', 'music.media.read', 'music.media.retain'] as $ability) {
        Gate::define($ability, fn ($user, $scope) => $user->is($actor) && $scope === $workspace);
    }
    $this->actingAs($actor);
    Filament::setCurrentPanel('app');
    MusicProjectsFilamentPlugin::make()->register(Filament::getCurrentPanel());
    Route::get('/app/music/sessions', Sessions::class)->name(Sessions::getRouteName());
    app('router')->getRoutes()->refreshNameLookups();
    Livewire::test(Sessions::class)->assertSet('workspaceId', $workspace)->assertSee('Save checkpoint')->assertSee('Audio execution is unavailable here');
    $scanner = Mockery::mock(MalwareScanner::class);
    $scanner->shouldReceive('clean')->once()->andReturnTrue();
    app()->instance(MalwareScanner::class, $scanner);
    $chunks = 'fmt '.pack('V', 16).pack('vvVVvv', 1, 1, 48000, 96000, 2, 16).'data'.pack('V', 4).pack('v*', 0, 16384);
    $bytes = 'RIFF'.pack('V', strlen($chunks) + 4).'WAVE'.$chunks;
    $assetId = (string) Str::uuid();
    $uploads = app(MediaUploads::class);
    $uploads->start($actor, new StartUpload($workspace, $assetId, 'Panel take', strlen($bytes), hash('sha256', $bytes)));
    $uploads->append($actor, $workspace, $assetId, 0, $bytes);
    $asset = $uploads->complete($actor, $workspace, $assetId);
    $document = SessionDocument::empty()->jsonSerialize();
    $document['assets'] = [$asset->reference()];
    $document['tracks'] = [['id' => (string) Str::uuid(), 'name' => 'Take', 'kind' => 'audio', 'parent_id' => null, 'media_ids' => [$assetId]]];
    $component = Livewire::test(SessionWorkspace::class, ['workspace' => $workspace, 'scope' => 'filament'])
        ->set('document', $document)->set('title', 'Panel recording')->call('save')->assertSet('version', 1)
        ->set('title', 'Interrupted edit')->call('save', true)->assertSet('version', 2)
        ->call('reviewRecovery', 1)->call('confirmRecovery')->assertSet('version', 3)->assertSet('title', 'Panel recording');
    $projectId = $component->get('projectId');
    $manifest = app(ProjectSessions::class)->export($actor, $workspace, $projectId);
    $component->set('importManifest', $manifest)->call('previewImport')->call('confirmImport')->assertSet('version', 1);
    expect(DB::table('music_media_retention')->where('asset_id', $assetId)->count())->toBe(4);
    $copyId = $component->get('projectId');
    $component->set('title', 'Keep this unsaved draft');
    TenantContext::set((int) $team->id + 100);
    $component->call('save')->assertSee('Access denied')->assertSet('title', 'Keep this unsaved draft')->assertSet('version', 1);
    expect(Sessions::canAccess())->toBeFalse()->and(app(ProjectSessions::class)->get($actor, $workspace, $copyId)->title)->toBe('Panel recording')
        ->and(DB::table('music_project_revisions')->count())->toBe(4);
    TenantContext::clear();
    $component->call('save')->assertSet('version', 2);
});
