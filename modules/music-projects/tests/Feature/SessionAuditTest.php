<?php

use Illuminate\Auth\GenericUser;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Liberu\Music\Projects\Commands\SaveSession;
use Liberu\Music\Projects\Contracts\SessionAudit;
use Liberu\Music\Projects\Data\SessionDocument;
use Liberu\Music\Projects\Data\SessionRevision;
use Liberu\Music\Projects\Events\SessionSaved;
use Liberu\Music\Projects\Services\ProjectSessions;

beforeEach(function () {
    $this->artisan('migrate', ['--path' => realpath(__DIR__.'/../../database/migrations'), '--realpath' => true])->assertSuccessful();
    Schema::create('test_session_audits', function (Blueprint $table) {
        $table->increments('id');
        $table->string('operation');
        $table->unsignedInteger('version');
        $table->unsignedInteger('source_version')->nullable();
    });
    $this->actor = new GenericUser(['id' => 1]);
    $this->projectId = (string) Str::uuid();
    Gate::define('music.projects.read', fn () => true);
    Gate::define('music.projects.write', fn () => true);
});

it('records the operation and recovery source inside the revision transaction exactly once', function () {
    $sink = Mockery::mock(SessionAudit::class);
    $sink->shouldReceive('record')->times(4)->andReturnUsing(function ($actor, SessionRevision $revision, $operation, $sourceVersion, ConnectionInterface $transaction) {
        expect($actor->id)->toBe(1)->and($transaction)->toBe(DB::connection())->and($transaction->transactionLevel())->toBeGreaterThan(0)
            ->and($transaction->table('music_project_revisions')->where('project_id', $revision->projectId)->where('version', $revision->version)->exists())->toBeTrue();
        $transaction->table('test_session_audits')->insert(['operation' => $operation, 'version' => $revision->version, 'source_version' => $sourceVersion]);
    });
    app()->instance(SessionAudit::class, $sink);
    $sessions = app(ProjectSessions::class);
    $command = new SaveSession('studio', $this->projectId, 'Song', SessionDocument::empty(), 0, 'original');
    $sessions->save($this->actor, $command);
    $sessions->save($this->actor, $command);
    // The original fingerprint must stay compatible with already persisted commands.
    $fingerprint = hash('sha256', json_encode(['1', 'studio', $this->projectId, 'Song', $command->document->checksum(), 0, false, false], JSON_THROW_ON_ERROR));
    expect(DB::table('music_project_revisions')->value('fingerprint'))->toBe($fingerprint);
    $sessions->save($this->actor, new SaveSession('studio', $this->projectId, 'Draft', SessionDocument::empty(), 1, 'autosave', true));
    $sessions->recover($this->actor, 'studio', $this->projectId, 1, 2, 'recovery');
    $sessions->recover($this->actor, 'studio', $this->projectId, 1, 2, 'recovery');
    $manifest = $sessions->export($this->actor, 'studio', $this->projectId);
    $copy = (string) Str::uuid();
    $sessions->previewImport($this->actor, 'studio', $copy, $manifest);
    $sessions->import($this->actor, 'studio', $copy, $manifest, 'copy');
    $sessions->import($this->actor, 'studio', $copy, $manifest, 'copy');
    $records = DB::table('test_session_audits')->orderBy('id')->get();
    expect($records->pluck('operation')->all())->toBe(['checkpoint', 'autosave', 'recovery', 'import'])
        ->and($records->pluck('version')->all())->toBe([1, 2, 3, 1])->and($records->pluck('source_version')->all())->toBe([null, null, 1, null]);
});

it('rolls back sink writes and a newly reserved project on audit failure then safely retries', function () {
    $fail = true;
    $sink = Mockery::mock(SessionAudit::class);
    $sink->shouldReceive('record')->twice()->andReturnUsing(function ($actor, $revision, $operation, $sourceVersion, $transaction) use (&$fail) {
        $transaction->table('test_session_audits')->insert(['operation' => $operation, 'version' => $revision->version]);
        if ($fail) {
            throw new RuntimeException('Audit unavailable');
        }
    });
    app()->instance(SessionAudit::class, $sink);
    Event::fake([SessionSaved::class]);
    $sessions = app(ProjectSessions::class);
    $command = new SaveSession('studio', $this->projectId, 'Retry', SessionDocument::empty(), 0, 'stable-key');
    expect(fn () => $sessions->save($this->actor, $command))->toThrow(RuntimeException::class, 'Audit unavailable');
    expect(DB::table('music_projects')->count())->toBe(0)->and(DB::table('music_project_revisions')->count())->toBe(0)
        ->and(DB::table('test_session_audits')->count())->toBe(0);
    Event::assertNotDispatched(SessionSaved::class);
    $fail = false;
    expect($sessions->save($this->actor, $command)->version)->toBe(1)->and(DB::table('test_session_audits')->count())->toBe(1);
});

it('leaves existing history unchanged when the recovery audit fails', function () {
    $sessions = app(ProjectSessions::class);
    $sessions->save($this->actor, new SaveSession('studio', $this->projectId, 'Checkpoint', SessionDocument::empty(), 0, 'initial'));
    $sessions->save($this->actor, new SaveSession('studio', $this->projectId, 'Autosave', SessionDocument::empty(), 1, 'autosave', true));
    $sink = Mockery::mock(SessionAudit::class);
    $sink->shouldReceive('record')->once()->andThrow(new RuntimeException('Audit unavailable'));
    app()->instance(SessionAudit::class, $sink);
    Event::fake([SessionSaved::class]);
    expect(fn () => app(ProjectSessions::class)->recover($this->actor, 'studio', $this->projectId, 1, 2, 'recover'))->toThrow(RuntimeException::class);
    expect($sessions->get($this->actor, 'studio', $this->projectId)->title)->toBe('Autosave')
        ->and(DB::table('music_project_revisions')->count())->toBe(2)->and(DB::table('music_projects')->value('version'))->toBe(2);
    Event::assertNotDispatched(SessionSaved::class);
});
