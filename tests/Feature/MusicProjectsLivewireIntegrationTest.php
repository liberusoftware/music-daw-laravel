<?php

use App\Models\User;
use App\Providers\MusicModuleIntegrationProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Liberu\Foundation\Files\Contracts\MalwareScanner;
use Liberu\Music\MediaLibrary\Commands\StartUpload;
use Liberu\Music\MediaLibrary\Contracts\MediaAuthorizer;
use Liberu\Music\MediaLibrary\MusicMediaLibraryServiceProvider;
use Liberu\Music\MediaLibrary\Services\MediaUploads;
use Liberu\Music\MediaLibrary\Support\GateMediaAuthorizer;
use Liberu\Music\Projects\Commands\SaveSession;
use Liberu\Music\Projects\Data\SessionDocument;
use Liberu\Music\Projects\Services\ProjectSessions;
use Liberu\Music\ProjectsLivewire\Livewire\SessionWorkspace;
use Liberu\Music\ProjectsLivewire\MusicProjectsLivewireServiceProvider;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

it('keeps the installed Music workspace disabled until the host enables its provider', function () {
    expect(Route::has('music.projects.livewire.workspace'))->toBeFalse();
});

it('composes the Livewire session journey with actual verified and retained audio through the host', function () {
    $this->app->register(MusicProjectsLivewireServiceProvider::class);
    $this->app->register(MusicMediaLibraryServiceProvider::class);
    (new MusicModuleIntegrationProvider($this->app))->boot();
    // This contract fixture supplies synthetic workspace policies. Real host
    // membership/permission enforcement is covered by MusicMediaWorkspaceAuthorizationTest.
    app()->bind(MediaAuthorizer::class, GateMediaAuthorizer::class);
    $this->artisan('migrate', ['--path' => base_path('modules/music-media-library/database/migrations'), '--realpath' => true])->assertSuccessful();
    config()->set('music-media.disk', 'host-livewire-sessions');
    Storage::fake('host-livewire-sessions');
    $actor = User::factory()->create();
    foreach (['music.projects.list', 'music.projects.read', 'music.projects.write', 'music.media.upload', 'music.media.read', 'music.media.retain'] as $ability) {
        Gate::define($ability, fn ($user, $workspace) => $user->is($actor) && $workspace === 'studio');
    }
    $scanner = Mockery::mock(MalwareScanner::class);
    $scanner->shouldReceive('clean')->once()->andReturnTrue();
    app()->instance(MalwareScanner::class, $scanner);
    $format = pack('vvVVvv', 1, 1, 48000, 96000, 2, 16);
    $chunks = 'fmt '.pack('V', 16).$format.'data'.pack('V', 4).pack('v*', 0, 16384);
    $bytes = 'RIFF'.pack('V', strlen($chunks) + 4).'WAVE'.$chunks;
    $assetId = (string) Str::uuid();
    $uploads = app(MediaUploads::class);
    $uploads->start($actor, new StartUpload('studio', $assetId, 'Recorded take', strlen($bytes), hash('sha256', $bytes)));
    $uploads->append($actor, 'studio', $assetId, 0, $bytes);
    $asset = $uploads->complete($actor, 'studio', $assetId);
    $document = SessionDocument::empty()->jsonSerialize();
    $document['assets'] = [$asset->reference()];
    $document['tracks'] = [['id' => (string) Str::uuid(), 'name' => 'Take 1', 'kind' => 'audio', 'parent_id' => null, 'media_ids' => [$assetId]]];
    $component = Livewire::actingAs($actor)->test(SessionWorkspace::class, ['workspace' => 'studio'])
        ->set('document', $document)->set('title', 'Recorded session')->call('save')->assertSet('version', 1)
        ->set('title', 'Interrupted edit')->call('save', true)->assertSet('version', 2)
        ->call('reviewRecovery', 1)->call('confirmRecovery')->assertSet('version', 3)->assertSet('title', 'Recorded session');
    $sourceId = $component->get('projectId');
    $component->call('exportSaved')->assertFileDownloaded('session-'.$sourceId.'.music.json');
    $sessions = app(ProjectSessions::class);
    $manifest = $sessions->export($actor, 'studio', $sourceId);
    $component->set('importManifest', $manifest)->call('previewImport')->assertSee('Confirm import')
        ->call('confirmImport')->assertSet('version', 1)->assertSet('importPreview', null);
    $copyId = $component->get('projectId');
    expect($copyId)->not->toBe($sourceId)
        ->and($sessions->get($actor, 'studio', $copyId)->document->assets())->toBe([$asset->reference()])
        ->and(DB::table('music_media_retention')->where('asset_id', $assetId)->count())->toBe(4);
    $draft = ['format' => 'liberu.music.draft', 'schema_version' => 1, 'workspace_id' => 'studio', 'project_id' => $copyId,
        'version' => 1, 'template' => false, 'title' => 'Offline edit', 'document' => $document, 'command_key' => $component->get('commandKey')];
    $sessions->save($actor, new SaveSession('studio', $copyId, 'Concurrent edit', SessionDocument::fromArray($document), 1, 'concurrent'));
    $component->call('newSession')->call('restoreDraft', json_encode($draft))->assertSet('version', 1)->call('save')
        ->assertSet('title', 'Offline edit')->assertSee('Your draft is preserved. Back it up');
    expect($sessions->get($actor, 'studio', $copyId)->title)->toBe('Concurrent edit');
    $component->call('removeTrack', $document['tracks'][0]['id']);
    expect($component->get('document')['tracks'])->toBe([])->and($component->get('document')['assets'])->toBe([$asset->reference()]);
    Gate::define('music.media.read', fn () => false);
    $component->call('open', $sourceId)->assertSet('problem', 'Access denied. No changes were saved.')->assertSet('title', 'Offline edit');
    Livewire::test(SessionWorkspace::class, ['workspace' => 'other'])->assertSee('Access denied');
    expect(fn () => $component->set('workspaceId', 'other'))->toThrow(CannotUpdateLockedPropertyException::class);
});
