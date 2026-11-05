<?php

use App\Models\User;
use App\Providers\MusicModuleIntegrationProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Liberu\Foundation\Files\Contracts\MalwareScanner;
use Liberu\Music\MediaLibrary\Commands\StartUpload;
use Liberu\Music\MediaLibrary\MusicMediaLibraryServiceProvider;
use Liberu\Music\MediaLibrary\Services\MediaRetention;
use Liberu\Music\MediaLibrary\Services\MediaUploads;
use Liberu\Music\MediaLibrary\Support\ScopedMediaAccess;
use Liberu\Music\Projects\Data\SessionDocument;
use Liberu\Music\ProjectsApi\MusicProjectsApiServiceProvider;

it('saves recovers and imports API sessions with actual verified media and transactional retention', function () {
    $this->app->register(MusicMediaLibraryServiceProvider::class);
    (new MusicModuleIntegrationProvider($this->app))->boot();
    $this->app->register(MusicProjectsApiServiceProvider::class);
    app('router')->getRoutes()->refreshNameLookups();
    $this->artisan('migrate', ['--path' => base_path('modules/music-media-library/database/migrations'), '--realpath' => true])->assertSuccessful();
    config()->set('music-media.disk', 'api-command-media');
    Storage::fake('api-command-media');
    $actor = User::factory()->create();
    foreach (['music.projects.write', 'music.projects.read', 'music.projects.list', 'music.media.upload', 'music.media.read', 'music.media.retain'] as $ability) {
        Gate::define($ability, fn ($user, $workspace) => $user->is($actor) && $workspace === 'studio');
    }
    Sanctum::actingAs($actor, ['music.projects.read', 'music.projects.write', 'music.projects.preview']);
    $scanner = Mockery::mock(MalwareScanner::class);
    $scanner->shouldReceive('clean')->once()->andReturn(true);
    app()->instance(MalwareScanner::class, $scanner);
    $format = pack('vvVVvv', 1, 1, 48000, 96000, 2, 16);
    $chunks = 'fmt '.pack('V', 16).$format.'data'.pack('V', 4).pack('v*', 0, 32767);
    $bytes = 'RIFF'.pack('V', strlen($chunks) + 4).'WAVE'.$chunks;
    $uploads = app(MediaUploads::class);
    $assetId = (string) Str::uuid();
    $uploads->start($actor, new StartUpload('studio', $assetId, 'Verified take', strlen($bytes), hash('sha256', $bytes)));
    $uploads->append($actor, 'studio', $assetId, 0, $bytes);
    $asset = $uploads->complete($actor, 'studio', $assetId);
    $document = SessionDocument::empty()->jsonSerialize();
    $document['assets'] = [$asset->reference()];
    $document['tracks'] = [['id' => (string) Str::uuid(), 'name' => 'Take', 'kind' => 'audio', 'parent_id' => null, 'media_ids' => [$assetId]]];
    $payload = ['title' => 'API session', 'document' => $document];
    $headers = fn (int $version, string $key): array => ['If-Match' => '"'.$version.'"', 'Idempotency-Key' => $key];
    $id = (string) Str::uuid();
    $base = '/api/v1/music-projects/workspaces/studio/sessions/'.$id;
    $this->postJson($base.'/checkpoints', $payload, $headers(0, 'create'))->assertCreated()->assertJsonPath('data.version', 1);
    $this->postJson($base.'/checkpoints', array_replace($payload, ['autosave' => true, 'title' => 'Draft']), $headers(1, 'draft'))
        ->assertCreated()->assertJsonPath('data.autosave', true);
    $manifest = $this->getJson($base.'/manifest')->assertSuccessful()->json('data');
    $copy = '/api/v1/music-projects/workspaces/studio/sessions/'.Str::uuid();
    $this->postJson($copy.'/import-preview', ['manifest' => json_encode($manifest)])->assertSuccessful();
    $this->postJson($copy.'/imports', ['manifest' => json_encode($manifest)], $headers(0, 'import'))->assertCreated()->assertJsonPath('data.version', 1);
    $this->postJson($base.'/recoveries', ['source_version' => 1], $headers(2, 'recover'))->assertCreated()->assertJsonPath('data.version', 3)->assertJsonPath('data.autosave', false);
    $this->postJson($base.'/checkpoints', $payload, $headers(0, 'create'))->assertCreated()->assertJsonPath('data.version', 1);
    $this->postJson($base.'/checkpoints', $payload, $headers(1, 'stale'))->assertStatus(412);
    $this->postJson($base.'/checkpoints', array_replace($payload, ['title' => 'Changed']), $headers(0, 'create'))->assertConflict();
    expect(DB::table('music_project_revisions')->count())->toBe(4)
        ->and(DB::table('music_media_retention')->where('asset_id', $assetId)->count())->toBe(4)
        ->and(app(MediaRetention::class)->isProtected($actor, 'studio', $assetId))->toBeTrue();
    Gate::define('music.media.read', fn () => false);
    $this->postJson($base.'/checkpoints', $payload, $headers(0, 'create'))->assertForbidden();
});

it('keeps the media bridge unavailable when the host has no configured identity provider', function () {
    config()->set('auth.guards.'.config('auth.defaults.guard').'.provider', null);
    $this->app->register(MusicMediaLibraryServiceProvider::class);
    (new MusicModuleIntegrationProvider($this->app))->boot();
    expect(app(ScopedMediaAccess::class)->authorized(1, (string) Str::uuid(), 'read'))->toBeFalse();
});
