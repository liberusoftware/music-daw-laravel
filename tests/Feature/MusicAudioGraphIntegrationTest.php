<?php

use App\Models\User;
use App\Providers\MusicModuleIntegrationProvider;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Liberu\Foundation\Audit\Contracts\AuditRecorder;
use Liberu\Foundation\Audit\Support\DatabaseAuditRecorder;
use Liberu\Foundation\Files\Contracts\MalwareScanner;
use Liberu\Music\AudioEngine\Commands\SaveGraph;
use Liberu\Music\AudioEngine\Data\GraphDefinition;
use Liberu\Music\AudioEngine\MusicAudioEngineServiceProvider;
use Liberu\Music\AudioEngine\Services\SessionGraphs;
use Liberu\Music\MediaLibrary\Commands\StartUpload;
use Liberu\Music\MediaLibrary\Contracts\MediaAuthorizer;
use Liberu\Music\MediaLibrary\MusicMediaLibraryServiceProvider;
use Liberu\Music\MediaLibrary\Services\MediaUploads;
use Liberu\Music\MediaLibrary\Support\GateMediaAuthorizer;
use Liberu\Music\Projects\Commands\SaveSession;
use Liberu\Music\Projects\Data\SessionDocument;
use Liberu\Music\Projects\Services\ProjectSessions;

beforeEach(function () {
    $this->actor = User::factory()->create();
    $this->app->register(MusicAudioEngineServiceProvider::class);
    $this->app->register(MusicMediaLibraryServiceProvider::class);
    (new MusicModuleIntegrationProvider($this->app))->boot();
    // Synthetic contract policies isolate graph/audit/retention composition here.
    // Live team membership and grants are covered by MusicMediaWorkspaceAuthorizationTest.
    app()->bind(MediaAuthorizer::class, GateMediaAuthorizer::class);
    $this->artisan('migrate', ['--path' => base_path('modules/music-media-library/database/migrations'), '--realpath' => true])->assertSuccessful();
    config()->set('music-media.disk', 'graph-audio');
    Storage::fake('graph-audio');
    foreach (['upload', 'read', 'retain'] as $ability) {
        Gate::define('music.media.'.$ability, fn ($actor, $workspace) => $actor->is($this->actor) && $workspace === 'studio');
    }
    foreach (['read', 'write'] as $ability) {
        Gate::define('music.projects.'.$ability, fn ($actor, $workspace) => $actor->is($this->actor) && $workspace === 'studio');
    }
    $scanner = Mockery::mock(MalwareScanner::class);
    $scanner->shouldReceive('clean')->once()->andReturnTrue();
    app()->instance(MalwareScanner::class, $scanner);
    $chunks = 'fmt '.pack('V', 16).pack('vvVVvv', 1, 1, 48000, 96000, 2, 16).'data'.pack('V', 4).pack('v*', 0, 16384);
    $this->bytes = 'RIFF'.pack('V', strlen($chunks) + 4).'WAVE'.$chunks;
    $this->assetId = (string) Str::uuid();
    $uploads = app(MediaUploads::class);
    $uploads->start($this->actor, new StartUpload('studio', $this->assetId, 'Graph recording', strlen($this->bytes), hash('sha256', $this->bytes)));
    $uploads->append($this->actor, 'studio', $this->assetId, 0, $this->bytes);
    $asset = $uploads->complete($this->actor, 'studio', $this->assetId);
    $this->project = (string) Str::uuid();
    $document = SessionDocument::empty()->jsonSerialize();
    $document['assets'] = [$asset->reference()];
    $document['modules'] = ['opaque-plugin' => ['schema_version' => 9, 'state' => ['sample_position' => PHP_INT_MAX]]];
    $this->document = SessionDocument::fromArray($document);
    app(ProjectSessions::class)->save($this->actor, new SaveSession('studio', $this->project, 'Audio graph', $this->document, 0, 'initial'));
    $source = (string) Str::uuid();
    $output = (string) Str::uuid();
    $this->graph = GraphDefinition::fromArray(['schema_version' => 1, 'buffer_frames' => 128, 'nodes' => [
        ['id' => $source, 'kind' => 'media', 'channels' => 1, 'latency_frames' => 0, 'media_id' => $this->assetId],
        ['id' => $output, 'kind' => 'output', 'channels' => 1, 'latency_frames' => 0],
    ], 'edges' => [['from' => $source, 'to' => $output]]]);
    $this->command = new SaveGraph('studio', $this->project, $this->graph, 1, 'graph');
});

it('composes verified graph references with the real retention and audit owners exactly once', function () {
    $graphs = app(SessionGraphs::class);
    $snapshot = $graphs->save($this->actor, $this->command);
    expect($graphs->save($this->actor, $this->command)->checksum())->toBe($snapshot->checksum())
        ->and($graphs->snapshot($this->actor, 'studio', $this->project, 2)->checksum())->toBe($snapshot->checksum())
        ->and($snapshot->jsonSerialize()['assets'][0]['frames'])->toBe('2')
        ->and($snapshot->jsonSerialize()['assets'][0]['sha256'])->toBe(hash('sha256', $this->bytes))
        ->and(DB::table('music_media_retention')->where('asset_id', $this->assetId)->count())->toBe(2)
        ->and(DB::table('music_project_revisions')->count())->toBe(2)
        ->and(DB::table('activity_log')->where('subject_type', 'music-session')->count())->toBe(2);
    $saved = app(ProjectSessions::class)->get($this->actor, 'studio', $this->project);
    expect($saved->document->assets())->toBe($this->document->assets())
        ->and($saved->document->jsonSerialize()['modules']['opaque-plugin']['state']['sample_position'])->toBe(PHP_INT_MAX);
    Gate::define('music.media.read', fn () => false);
    expect(fn () => $graphs->snapshot($this->actor, 'studio', $this->project))->toThrow(AuthorizationException::class)
        ->and(fn () => $graphs->save($this->actor, $this->command))->toThrow(AuthorizationException::class);
    expect(DB::table('music_project_revisions')->count())->toBe(2);
});

it('rolls back graph audit and media retention on failure and accepts the same retry', function () {
    $audit = Mockery::mock(AuditRecorder::class);
    $audit->shouldReceive('record')->once()->andReturnUsing(function (...$arguments) {
        (new DatabaseAuditRecorder())->record(...$arguments);
        throw new RuntimeException('Failed graph audit');
    });
    app()->instance(AuditRecorder::class, $audit);
    expect(fn () => app(SessionGraphs::class)->save($this->actor, $this->command))->toThrow(RuntimeException::class, 'graph audit');
    expect(DB::table('music_project_revisions')->count())->toBe(1)
        ->and(DB::table('music_media_retention')->where('asset_id', $this->assetId)->count())->toBe(1)
        ->and(DB::table('activity_log')->where('subject_type', 'music-session')->count())->toBe(1)
        ->and(app(ProjectSessions::class)->get($this->actor, 'studio', $this->project)->document->checksum())->toBe($this->document->checksum());
    app()->instance(AuditRecorder::class, new DatabaseAuditRecorder());
    expect(app(SessionGraphs::class)->save($this->actor, $this->command)->jsonSerialize()['project_version'])->toBe('2')
        ->and(DB::table('music_project_revisions')->count())->toBe(2);
});
