<?php

use App\Models\User;
use App\Providers\MusicModuleIntegrationProvider;
use App\Support\UploadedSessionMedia;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Database\RecordNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Liberu\Foundation\Files\Contracts\MalwareScanner;
use Liberu\Foundation\Files\Contracts\MediaAccess;
use Liberu\Music\MediaLibrary\Commands\RestoreAsset;
use Liberu\Music\MediaLibrary\Commands\StartUpload;
use Liberu\Music\MediaLibrary\Exceptions\InvalidAudio;
use Liberu\Music\MediaLibrary\MusicMediaLibraryServiceProvider;
use Liberu\Music\MediaLibrary\Services\MediaUploads;
use Liberu\Music\Projects\Commands\SaveSession;
use Liberu\Music\Projects\Contracts\VerifiedMedia;
use Liberu\Music\Projects\Data\SessionDocument;
use Liberu\Music\Projects\Services\ProjectSessions;
use Liberu\Music\Projects\Support\UnavailableMedia;

it('keeps installed but disabled media unavailable to session composition', function () {
    expect(app(VerifiedMedia::class))->toBeInstanceOf(UnavailableMedia::class)
        ->and(app()->bound(MediaAccess::class))->toBeFalse();
});

it('saves, recovers and reopens portable sessions using actual verified uploaded audio', function () {
    $this->assertGuest();
    $this->app->register(MusicMediaLibraryServiceProvider::class);
    (new MusicModuleIntegrationProvider($this->app))->boot();
    $this->artisan('migrate', ['--path' => base_path('modules/music-media-library/database/migrations'), '--realpath' => true])->assertSuccessful();
    config()->set('music-media.disk', 'music-session-integration');
    $disk = Storage::fake('music-session-integration');
    $actor = User::factory()->create();
    $other = User::factory()->create();
    foreach (['music.projects.write', 'music.projects.read', 'music.media.upload', 'music.media.read', 'music.media.retain'] as $ability) {
        Gate::define($ability, fn ($user, $workspace) => $user->getKey() === $actor->getKey() && in_array($workspace, ['studio', 'other']));
    }
    $scanner = Mockery::mock(MalwareScanner::class);
    $scanner->shouldReceive('clean')->once()->andReturnUsing(fn ($path) => is_file($path));
    app()->instance(MalwareScanner::class, $scanner);
    $format = pack('vvVVvv', 1, 1, 48000, 96000, 2, 16);
    $chunks = 'fmt '.pack('V', 16).$format.'data'.pack('V', 4).pack('v*', 0, 16384);
    $bytes = 'RIFF'.pack('V', strlen($chunks) + 4).'WAVE'.$chunks;
    $mediaId = (string) Str::uuid();
    $uploads = app(MediaUploads::class);
    $uploads->start($actor, new StartUpload('studio', $mediaId, 'Recorded take', strlen($bytes), hash('sha256', $bytes)));
    $uploads->append($actor, 'studio', $mediaId, 0, $bytes);
    $projectId = (string) Str::uuid();
    $input = SessionDocument::empty()->jsonSerialize();
    $input['assets'] = [['id' => $mediaId, 'sha256' => hash('sha256', $bytes), 'sample_rate' => 48000, 'channels' => 1, 'frames' => 2]];
    $input['tracks'] = [['id' => (string) Str::uuid(), 'name' => 'Take 1', 'kind' => 'audio', 'parent_id' => null, 'media_ids' => [$mediaId]]];
    $document = SessionDocument::fromArray($input);
    $command = new SaveSession('studio', $projectId, 'Recorded session', $document, 0, 'create');
    $sessions = app(ProjectSessions::class);
    expect(app(VerifiedMedia::class))->toBeInstanceOf(UploadedSessionMedia::class)
        ->and(app()->bound(MediaAccess::class))->toBeFalse();
    expect(fn () => $sessions->save($actor, $command))->toThrow(AuthorizationException::class);
    expect(DB::table('music_projects')->count())->toBe(0);
    $asset = $uploads->complete($actor, 'studio', $mediaId);
    Gate::define('music.media.retain', fn () => false);
    expect(fn () => $sessions->save($actor, $command))->toThrow(AuthorizationException::class);
    expect(DB::table('music_projects')->count())->toBe(0)
        ->and(DB::table('music_media_retention')->count())->toBe(0);
    Gate::define('music.media.retain', fn ($user, $workspace) => $user->getKey() === $actor->getKey() && $workspace === 'studio');
    DB::unprepared("CREATE TRIGGER refuse_revision BEFORE INSERT ON music_project_revisions BEGIN SELECT RAISE(ABORT, 'revision failed'); END");
    expect(fn () => $sessions->save($actor, $command))->toThrow(QueryException::class);
    expect(DB::table('music_projects')->count())->toBe(0)
        ->and(DB::table('music_media_retention')->count())->toBe(0);
    DB::unprepared('DROP TRIGGER refuse_revision');
    $first = $sessions->save($actor, $command);
    $sessions->save($actor, $command);
    expect(DB::table('music_media_retention')->count())->toBe(1)
        ->and(DB::table('music_media_retention')->value('reference_id'))->toBe($projectId.':1');
    expect($first->document->assets())->toBe([$asset->reference()]);
    $draft = $sessions->save($actor, new SaveSession('studio', $projectId, 'Autosaved take', $document, 1, 'draft', true));
    $recovered = $sessions->recover($actor, 'studio', $projectId, $draft->version, 2, 'recover');
    $manifest = $sessions->export($actor, 'studio', $projectId);
    $reopened = $sessions->import($actor, 'studio', (string) Str::uuid(), $manifest, 'reopen');
    expect(DB::table('music_media_retention')->count())->toBe(4)
        ->and(DB::table('music_media_retention')->where('asset_id', $mediaId)->pluck('reference_id')->all())
        ->toContain($projectId.':1', $projectId.':2', $projectId.':3', $reopened->projectId.':1');
    expect($recovered->autosave)->toBeFalse()->and($recovered->version)->toBe(3)
        ->and($reopened->document->jsonSerialize())->toBe($document->jsonSerialize())
        ->and($sessions->get($actor, 'studio', $projectId)->document->assets())->toBe([$asset->reference()]);
    expect(fn () => $sessions->save($other, $command))->toThrow(AuthorizationException::class);
    expect(fn () => $sessions->save($actor, new SaveSession('other', (string) Str::uuid(), 'Cross workspace', $document, 0, 'cross')))->toThrow(RecordNotFoundException::class);
    $tampered = $input;
    $tampered['assets'][0]['sha256'] = str_repeat('0', 64);
    expect(fn () => $sessions->save($actor, new SaveSession('studio', (string) Str::uuid(), 'Tampered', SessionDocument::fromArray($tampered), 0, 'tamper')))->toThrow(ValidationException::class);
    Gate::define('music.media.read', fn () => false);
    expect(fn () => $sessions->get($actor, 'studio', $projectId))->toThrow(AuthorizationException::class);
    expect(fn () => $sessions->save($actor, $command))->toThrow(AuthorizationException::class);
    expect(fn () => $sessions->history($actor, 'studio', $projectId))->toThrow(AuthorizationException::class);
    Gate::define('music.media.read', fn ($user, $workspace) => $user->getKey() === $actor->getKey() && $workspace === 'studio');
    $disk->delete(DB::table('music_media_uploads')->where('id', $mediaId)->value('blob_key'));
    expect(fn () => $sessions->get($actor, 'studio', $projectId))->toThrow(InvalidAudio::class, 'missing');
});

it('reopens a saved session after authorized restoration without rewriting its revision', function () {
    $this->app->register(MusicMediaLibraryServiceProvider::class);
    (new MusicModuleIntegrationProvider($this->app))->boot();
    $this->artisan('migrate', ['--path' => base_path('modules/music-media-library/database/migrations'), '--realpath' => true])->assertSuccessful();
    config()->set('music-media.disk', 'session-restore-test');
    $disk = Storage::fake('session-restore-test');
    $actor = User::factory()->create();
    foreach (['music.media.upload', 'music.media.read', 'music.media.restore', 'music.media.retain', 'music.projects.read', 'music.projects.write'] as $ability) {
        Gate::define($ability, fn ($user, $workspace) => $user->getKey() === $actor->getKey() && $workspace === 'studio');
    }
    $scanner = Mockery::mock(MalwareScanner::class);
    $scanner->shouldReceive('clean')->twice()->andReturn(true);
    app()->instance(MalwareScanner::class, $scanner);
    $format = pack('vvVVvv', 1, 1, 48000, 96000, 2, 16);
    $chunks = 'fmt '.pack('V', 16).$format.'data'.pack('V', 4).pack('v*', 0, 16384);
    $bytes = 'RIFF'.pack('V', strlen($chunks) + 4).'WAVE'.$chunks;
    $uploads = app(MediaUploads::class);
    $receive = function () use ($actor, $bytes, $uploads): string {
        $id = (string) Str::uuid();
        $uploads->start($actor, new StartUpload('studio', $id, 'Recording', strlen($bytes), hash('sha256', $bytes)));
        $uploads->append($actor, 'studio', $id, 0, $bytes);

        return $id;
    };
    $originalId = $receive();
    $asset = $uploads->complete($actor, 'studio', $originalId);
    $input = SessionDocument::empty()->jsonSerialize();
    $input['assets'] = [$asset->reference()];
    $input['tracks'] = [['id' => (string) Str::uuid(), 'name' => 'Keep this take', 'kind' => 'audio', 'parent_id' => null, 'media_ids' => [$originalId]]];
    $projectId = (string) Str::uuid();
    $sessions = app(ProjectSessions::class);
    $saved = $sessions->save($actor, new SaveSession('studio', $projectId, 'Before restore', SessionDocument::fromArray($input), 0, 'create'));
    $storedDocument = DB::table('music_project_revisions')->where('project_id', $projectId)->value('document');
    $disk->delete(DB::table('music_media_uploads')->where('id', $originalId)->value('blob_key'));
    expect(fn () => $sessions->get($actor, 'studio', $projectId))->toThrow(InvalidAudio::class);
    $restored = $uploads->restore($actor, new RestoreAsset('studio', $originalId, $receive(), 'repair'));
    expect($restored->id)->toBe($originalId)
        ->and($sessions->get($actor, 'studio', $projectId)->jsonSerialize())->toBe($saved->jsonSerialize())
        ->and(DB::table('music_project_revisions')->where('project_id', $projectId)->value('document'))->toBe($storedDocument)
        ->and(DB::table('music_project_revisions')->where('project_id', $projectId)->count())->toBe(1);
});
