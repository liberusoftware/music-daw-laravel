<?php

use App\Models\User;
use App\Providers\MusicModuleIntegrationProvider;
use App\Support\AuditedSessionRevisions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Liberu\Foundation\Audit\Contracts\AuditRecorder;
use Liberu\Foundation\Audit\Support\DatabaseAuditRecorder;
use Liberu\Foundation\Files\Contracts\MalwareScanner;
use Liberu\Music\MediaLibrary\Commands\StartUpload;
use Liberu\Music\MediaLibrary\Contracts\MediaAuthorizer;
use Liberu\Music\MediaLibrary\MusicMediaLibraryServiceProvider;
use Liberu\Music\MediaLibrary\Services\MediaUploads;
use Liberu\Music\MediaLibrary\Support\GateMediaAuthorizer;
use Liberu\Music\Projects\Commands\SaveSession;
use Liberu\Music\Projects\Data\SessionDocument;
use Liberu\Music\Projects\Data\SessionRevision;
use Liberu\Music\Projects\Events\SessionSaved;
use Liberu\Music\Projects\Exceptions\IdempotencyConflict;
use Liberu\Music\Projects\Exceptions\RevisionConflict;
use Liberu\Music\Projects\Services\ProjectSessions;
use Liberu\Music\ProjectsApi\MusicProjectsApiServiceProvider;

beforeEach(function () {
    $this->actor = User::factory()->create();
    foreach (['read', 'write'] as $operation) {
        Gate::define('music.projects.'.$operation, fn ($actor, $workspace) => $actor->is($this->actor) && $workspace === 'studio');
    }
    $this->projectId = (string) Str::uuid();
});

it('atomically audits checkpoints autosaves recovery and import once without recording private document content', function () {
    $correlation = (string) Str::uuid();
    request()->attributes->set('correlation_id', $correlation);
    $document = SessionDocument::empty()->jsonSerialize();
    $document['modules'] = ['private-instrument' => ['schema_version' => 1, 'state' => ['preset' => 'private-opaque-secret']]];
    $command = new SaveSession('studio', $this->projectId, 'Private song title', SessionDocument::fromArray($document), 0, 'create');
    $sessions = app(ProjectSessions::class);
    $sessions->save($this->actor, $command);
    $sessions->save($this->actor, $command);
    $sessions->save($this->actor, new SaveSession('studio', $this->projectId, 'Autosaved title', $command->document, 1, 'autosave', true));
    $sessions->recover($this->actor, 'studio', $this->projectId, 1, 2, 'recover');
    $sessions->recover($this->actor, 'studio', $this->projectId, 1, 2, 'recover');
    $manifest = $sessions->export($this->actor, 'studio', $this->projectId);
    $copyId = (string) Str::uuid();
    $sessions->previewImport($this->actor, 'studio', $copyId, $manifest);
    $sessions->import($this->actor, 'studio', $copyId, $manifest, 'import');
    $sessions->import($this->actor, 'studio', $copyId, $manifest, 'import');
    $rows = DB::table('activity_log')->where('log_name', 'audit')->where('subject_type', 'music-session')->orderBy('id')->get();
    expect($rows)->toHaveCount(4)->and($rows->pluck('event')->all())->toBe(['music.session.checkpoint', 'music.session.autosave', 'music.session.recovery', 'music.session.import']);
    foreach ($rows as $index => $row) {
        $changes = json_decode($row->attribute_changes, true, flags: JSON_THROW_ON_ERROR);
        $properties = json_decode($row->properties, true, flags: JSON_THROW_ON_ERROR);
        expect($row->subject_id)->toBeNull()->and((int) $row->causer_id)->toBe($this->actor->id)
            ->and($row->causer_type)->toBe($this->actor->getMorphClass())->and($row->tenant_ref)->toBe('studio')
            ->and($row->correlation_id)->toBe($correlation)->and($properties['tenant_id'])->toBe('studio')
            ->and($changes['after']['checksum'])->toBe($command->document->checksum())
            ->and($changes['after']['project_id'])->toBe($index === 3 ? $copyId : $this->projectId)
            ->and($changes['after']['source_version'])->toBe($index === 2 ? 1 : null)
            ->and($row->attribute_changes)->not->toContain('private-instrument', 'private-opaque-secret', 'Private song title', 'Autosaved title')
            ->and($row->record_hash)->toHaveLength(64);
        if ($index > 0) {
            expect($row->previous_hash)->toBe($rows[$index - 1]->record_hash);
        }
    }
    expect(DB::table('music_project_revisions')->count())->toBe(4);
});

it('rolls back the revision and a written audit row when the configured recorder fails then permits a safe retry', function () {
    $recorder = Mockery::mock(AuditRecorder::class);
    $recorder->shouldReceive('record')->once()->andReturnUsing(function (...$arguments) {
        (new DatabaseAuditRecorder())->record(...$arguments);
        throw new RuntimeException('Audit storage failed after insert');
    });
    app()->instance(AuditRecorder::class, $recorder);
    Event::fake([SessionSaved::class]);
    $command = new SaveSession('studio', $this->projectId, 'Retry draft', SessionDocument::empty(), 0, 'same-command');
    expect(fn () => app(ProjectSessions::class)->save($this->actor, $command))->toThrow(RuntimeException::class, 'Audit storage failed');
    expect(DB::table('music_projects')->where('id', $this->projectId)->exists())->toBeFalse()
        ->and(DB::table('music_project_revisions')->count())->toBe(0)
        ->and(DB::table('activity_log')->where('subject_type', 'music-session')->count())->toBe(0);
    Event::assertNotDispatched(SessionSaved::class);
    app()->instance(AuditRecorder::class, new DatabaseAuditRecorder());
    expect(app(ProjectSessions::class)->save($this->actor, $command)->version)->toBe(1)
        ->and(DB::table('activity_log')->where('subject_type', 'music-session')->count())->toBe(1);
});

it('does not audit denied stale or conflicting commands and retains the authoritative history', function () {
    $sessions = app(ProjectSessions::class);
    $command = new SaveSession('studio', $this->projectId, 'Original', SessionDocument::empty(), 0, 'create');
    $sessions->save($this->actor, $command);
    expect(fn () => $sessions->save($this->actor, new SaveSession('studio', $this->projectId, 'Changed', SessionDocument::empty(), 0, 'create')))->toThrow(IdempotencyConflict::class)
        ->and(fn () => $sessions->save($this->actor, new SaveSession('studio', $this->projectId, 'Stale', SessionDocument::empty(), 0, 'different')))->toThrow(RevisionConflict::class);
    Gate::define('music.projects.write', fn () => false);
    expect(fn () => $sessions->save($this->actor, $command))->toThrow(AuthorizationException::class)
        ->and(fn () => $sessions->recover($this->actor, 'studio', $this->projectId, 1, 1, 'denied-recovery'))->toThrow(AuthorizationException::class)
        ->and(DB::table('activity_log')->where('subject_type', 'music-session')->count())->toBe(1)
        ->and(DB::table('music_project_revisions')->count())->toBe(1);
});

it('rejects a mismatched or inactive transaction before calling the foundation recorder', function () {
    $recorder = Mockery::mock(AuditRecorder::class);
    $recorder->shouldNotReceive('record');
    $audit = new AuditedSessionRevisions($recorder);
    $revision = new SessionRevision('studio', $this->projectId, 'Draft', 1, SessionDocument::empty(), false, false);
    $other = Mockery::mock(ConnectionInterface::class);
    expect(fn () => $audit->record($this->actor, $revision, 'checkpoint', null, $other))->toThrow(LogicException::class);
    $manager = DB::getFacadeRoot();
    try {
        $other->shouldReceive('transactionLevel')->once()->andReturn(0);
        DB::shouldReceive('connection')->once()->andReturn($other);
        expect(fn () => $audit->record($this->actor, $revision, 'checkpoint', null, $other))->toThrow(LogicException::class);
    } finally {
        DB::swap($manager);
    }
});

it('rolls back media retention with a failed recovery audit and retries the same command without losing audio', function () {
    $this->app->register(MusicMediaLibraryServiceProvider::class);
    (new MusicModuleIntegrationProvider($this->app))->boot();
    // This contract fixture supplies synthetic workspace policies. Real host
    // membership/permission enforcement is covered by MusicMediaWorkspaceAuthorizationTest.
    app()->bind(MediaAuthorizer::class, GateMediaAuthorizer::class);
    $this->artisan('migrate', ['--path' => base_path('modules/music-media-library/database/migrations'), '--realpath' => true])->assertSuccessful();
    config()->set('music-media.disk', 'audit-recovery-audio');
    Storage::fake('audit-recovery-audio');
    foreach (['upload', 'read', 'retain'] as $ability) {
        Gate::define('music.media.'.$ability, fn ($actor, $workspace) => $actor->is($this->actor) && $workspace === 'studio');
    }
    $scanner = Mockery::mock(MalwareScanner::class);
    $scanner->shouldReceive('clean')->once()->andReturnTrue();
    app()->instance(MalwareScanner::class, $scanner);
    $chunks = 'fmt '.pack('V', 16).pack('vvVVvv', 1, 1, 48000, 96000, 2, 16).'data'.pack('V', 4).pack('v*', 0, 16384);
    $bytes = 'RIFF'.pack('V', strlen($chunks) + 4).'WAVE'.$chunks;
    $assetId = (string) Str::uuid();
    $uploads = app(MediaUploads::class);
    $uploads->start($this->actor, new StartUpload('studio', $assetId, 'Audit recording', strlen($bytes), hash('sha256', $bytes)));
    $uploads->append($this->actor, 'studio', $assetId, 0, $bytes);
    $asset = $uploads->complete($this->actor, 'studio', $assetId);
    $input = SessionDocument::empty()->jsonSerialize();
    $input['assets'] = [$asset->reference()];
    $input['tracks'] = [['id' => (string) Str::uuid(), 'name' => 'Take', 'kind' => 'audio', 'parent_id' => null, 'media_ids' => [$assetId]]];
    $document = SessionDocument::fromArray($input);
    $sessions = app(ProjectSessions::class);
    $sessions->save($this->actor, new SaveSession('studio', $this->projectId, 'Checkpoint', $document, 0, 'initial'));
    $sessions->save($this->actor, new SaveSession('studio', $this->projectId, 'Autosave', $document, 1, 'autosave', true));
    $hashes = DB::table('activity_log')->where('subject_type', 'music-session')->orderBy('id')->pluck('record_hash')->all();
    $recorder = Mockery::mock(AuditRecorder::class);
    $recorder->shouldReceive('record')->once()->andReturnUsing(function (...$arguments) {
        (new DatabaseAuditRecorder())->record(...$arguments);
        throw new RuntimeException('Audit failed after retention');
    });
    app()->instance(AuditRecorder::class, $recorder);
    Event::fake([SessionSaved::class]);
    expect(fn () => app(ProjectSessions::class)->recover($this->actor, 'studio', $this->projectId, 1, 2, 'retry-recovery'))->toThrow(RuntimeException::class);
    expect($sessions->get($this->actor, 'studio', $this->projectId)->version)->toBe(2)
        ->and(DB::table('music_project_revisions')->count())->toBe(2)
        ->and(DB::table('music_media_retention')->where('asset_id', $assetId)->count())->toBe(2)
        ->and(DB::table('activity_log')->where('subject_type', 'music-session')->orderBy('id')->pluck('record_hash')->all())->toBe($hashes);
    Event::assertNotDispatched(SessionSaved::class);
    app()->instance(AuditRecorder::class, new DatabaseAuditRecorder());
    $recovered = app(ProjectSessions::class)->recover($this->actor, 'studio', $this->projectId, 1, 2, 'retry-recovery');
    expect($recovered->version)->toBe(3)->and($recovered->title)->toBe('Checkpoint')->and($recovered->document->assets())->toBe([$asset->reference()])
        ->and(DB::table('music_media_retention')->where('asset_id', $assetId)->count())->toBe(3)
        ->and(DB::table('activity_log')->where('subject_type', 'music-session')->count())->toBe(3);
});

it('fails closed when the host audit dependency is unavailable', function () {
    unset(app()[AuditRecorder::class]);
    $command = new SaveSession('studio', $this->projectId, 'Protected draft', SessionDocument::empty(), 0, 'unavailable-audit');
    expect(fn () => app(ProjectSessions::class)->save($this->actor, $command))->toThrow(BindingResolutionException::class);
    expect(DB::table('music_projects')->where('id', $this->projectId)->exists())->toBeFalse()
        ->and(DB::table('music_project_revisions')->count())->toBe(0);
});

it('keeps audit query work bounded as the number of tracks grows through the actual HTTP save route', function () {
    $this->app->register(MusicProjectsApiServiceProvider::class);
    app('router')->getRoutes()->refreshNameLookups();
    Sanctum::actingAs($this->actor, ['music.projects.write']);
    $capturing = false;
    $queries = [];
    DB::listen(function ($query) use (&$capturing, &$queries) {
        if ($capturing && str_contains($query->sql, 'activity_log')) {
            $queries[] = $query->sql;
        }
    });
    $counts = [];
    foreach ([1, 64] as $trackCount) {
        $id = (string) Str::uuid();
        $document = SessionDocument::empty()->jsonSerialize();
        for ($index = 0; $index < $trackCount; $index++) {
            $document['tracks'][] = ['id' => (string) Str::uuid(), 'name' => 'Track '.$index, 'kind' => 'midi', 'parent_id' => null, 'media_ids' => []];
        }
        $queries = [];
        $capturing = true;
        $this->postJson('/api/v1/music-projects/workspaces/studio/sessions/'.$id.'/checkpoints', ['title' => 'Bounded audit', 'document' => $document],
            ['If-Match' => '"0"', 'Idempotency-Key' => 'create'])->assertCreated();
        $capturing = false;
        $counts[] = count($queries);
        expect(count($queries))->toBeGreaterThan(0)->toBeLessThanOrEqual(3);
        $summary = json_decode(DB::table('activity_log')->where('subject_type', 'music-session')->latest('id')->value('attribute_changes'), true, flags: JSON_THROW_ON_ERROR);
        expect($summary['after']['track_count'])->toBe($trackCount)->and($summary['after']['project_id'])->toBe($id);
    }
    expect($counts[1])->toBe($counts[0]);
});
