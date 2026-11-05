<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Liberu\Foundation\Files\Contracts\MediaAccess;
use Liberu\Music\Projects\Commands\BackfillSessionRetention;
use Liberu\Music\Projects\Commands\SaveSession;
use Liberu\Music\Projects\Contracts\SessionMediaRetention;
use Liberu\Music\Projects\Contracts\VerifiedMedia;
use Liberu\Music\Projects\Data\SessionDocument;
use Liberu\Music\Projects\Services\ProjectSessions;

beforeEach(function () {
    $this->artisan('migrate', ['--path' => realpath(__DIR__.'/../../database/migrations'), '--realpath' => true])->assertSuccessful();
    $this->actor = new GenericUser(['id' => 1]);
    foreach (['read', 'write', 'retain'] as $operation) {
        app(Gate::class)->define('music.projects.'.$operation, fn ($actor, $workspace) => $actor->id === 1 && $workspace === 'studio');
    }
    $this->sessions = app(ProjectSessions::class);
    $this->projectId = (string) Str::uuid();
    $this->saveRevision = fn (int $version) => $this->sessions->save($this->actor, new SaveSession('studio', $this->projectId, 'Song', SessionDocument::empty(), $version, 'save-'.$version));
});

it('resumes bounded historical batches with a fixed high water mark and explicit restart', function () {
    expect($this->sessions->retentionStatus($this->actor, 'studio')->jsonSerialize())->toBe([
        'schema_version' => 1, 'workspace_id' => 'studio', 'through_revision_id' => null,
        'last_revision_id' => 0, 'processed_revisions' => 0, 'complete' => false,
    ]);
    ($this->saveRevision)(0);
    ($this->saveRevision)(1);
    $first = $this->sessions->retainHistory($this->actor, new BackfillSessionRetention('studio', 1));
    expect($first->processedRevisions)->toBe(1)->and($first->complete())->toBeFalse();
    ($this->saveRevision)(2);
    $second = $this->sessions->retainHistory($this->actor, new BackfillSessionRetention('studio', 1));
    expect($second->processedRevisions)->toBe(2)->and($second->throughRevisionId)->toBe($first->throughRevisionId)
        ->and($second->complete())->toBeTrue();
    expect($this->sessions->retainHistory($this->actor, new BackfillSessionRetention('studio'))->jsonSerialize())->toBe($second->jsonSerialize());
    $restarted = $this->sessions->retainHistory($this->actor, new BackfillSessionRetention('studio', 25, true));
    expect($restarted->processedRevisions)->toBe(3)->and($restarted->complete())->toBeTrue()
        ->and($this->sessions->retentionStatus($this->actor, 'studio')->jsonSerialize())->toBe($restarted->jsonSerialize());
});

it('completes an empty workspace without scanning another workspace', function () {
    app(Gate::class)->define('music.projects.write', fn () => true);
    $this->sessions->save($this->actor, new SaveSession('other', $this->projectId, 'Private', SessionDocument::empty(), 0, 'other'));
    $status = $this->sessions->retainHistory($this->actor, new BackfillSessionRetention('studio'));
    expect($status->complete())->toBeTrue()->and($status->processedRevisions)->toBe(0)->and($status->throughRevisionId)->toBe(0);
});

it('authorizes status and every batch before creating progress', function () {
    foreach ([new GenericUser(['id' => 2]), $this->actor] as $actor) {
        $workspace = $actor->id === 1 ? 'other' : 'studio';
        expect(fn () => $this->sessions->retentionStatus($actor, $workspace))->toThrow(AuthorizationException::class);
        expect(fn () => $this->sessions->retainHistory($actor, new BackfillSessionRetention($workspace)))->toThrow(AuthorizationException::class);
    }
    expect(DB::table('music_project_retention_backfills')->count())->toBe(0);
});

it('rolls back the whole batch when a later revision loses read authorization', function () {
    ($this->saveRevision)(0);
    $otherProject = (string) Str::uuid();
    $this->sessions->save($this->actor, new SaveSession('studio', $otherProject, 'Private', SessionDocument::empty(), 0, 'private'));
    app(Gate::class)->define('music.projects.read', fn ($actor, $workspace, $project) => $project === $this->projectId);
    expect(fn () => $this->sessions->retainHistory($this->actor, new BackfillSessionRetention('studio')))->toThrow(AuthorizationException::class);
    expect(DB::table('music_project_retention_backfills')->count())->toBe(0);
    app(Gate::class)->define('music.projects.read', fn () => true);
    expect($this->sessions->retainHistory($this->actor, new BackfillSessionRetention('studio'))->processedRevisions)->toBe(2);
});

it('preserves the previous checkpoint when stored integrity fails', function () {
    ($this->saveRevision)(0);
    ($this->saveRevision)(1);
    $first = $this->sessions->retainHistory($this->actor, new BackfillSessionRetention('studio', 1));
    DB::table('music_project_revisions')->where('version', 2)->update(['checksum' => str_repeat('0', 64)]);
    expect(fn () => $this->sessions->retainHistory($this->actor, new BackfillSessionRetention('studio')))->toThrow(ValidationException::class, 'integrity');
    expect($this->sessions->retentionStatus($this->actor, 'studio')->jsonSerialize())->toBe($first->jsonSerialize());
});

it('validates workspace and batch bounds', function (string $workspace, int $limit) {
    expect(fn () => new BackfillSessionRetention($workspace, $limit))->toThrow(ValidationException::class);
})->with([['', 10], [str_repeat('a', 129), 10], ['studio', 0], ['studio', 26]]);

it('retains historical audio in the checkpoint transaction and retries a failed batch safely', function () {
    $asset = ['id' => 'recording', 'sha256' => str_repeat('a', 64), 'sample_rate' => 48000, 'channels' => 1, 'frames' => 48000];
    $input = SessionDocument::empty()->jsonSerialize();
    $input['assets'] = [$asset];
    $access = Mockery::mock(MediaAccess::class);
    $access->shouldReceive('authorized')->andReturn(true);
    app()->instance(MediaAccess::class, $access);
    $catalog = Mockery::mock(VerifiedMedia::class);
    $catalog->shouldReceive('resolve')->andReturn($asset);
    app()->instance(VerifiedMedia::class, $catalog);
    $retention = Mockery::mock(SessionMediaRetention::class);
    $retention->shouldReceive('retain')->andReturnNull();
    app()->instance(SessionMediaRetention::class, $retention);
    $sessions = app(ProjectSessions::class);
    foreach ([0, 1] as $version) {
        $sessions->save($this->actor, new SaveSession('studio', $this->projectId, 'Audio', SessionDocument::fromArray($input), $version, 'audio-'.$version));
    }
    Schema::create('backfill_references', function (Blueprint $table) {
        $table->integer('version')->primary();
    });
    $fail = true;
    $writer = Mockery::mock(SessionMediaRetention::class);
    $writer->shouldReceive('retain')->andReturnUsing(function ($actor, $workspace, $project, $version, $ids, $transaction) use (&$fail) {
        expect($actor)->toBe($this->actor)->and($workspace)->toBe('studio')->and($project)->toBe($this->projectId)
            ->and($ids)->toBe(['recording'])->and($transaction)->toBe(DB::connection())
            ->and($transaction->transactionLevel())->toBeGreaterThan(0);
        $transaction->table('backfill_references')->insertOrIgnore(['version' => $version]);
        if ($fail && $version === 2) {
            throw new RuntimeException('retention unavailable');
        }
    });
    app()->instance(SessionMediaRetention::class, $writer);
    $sessions = app(ProjectSessions::class);
    expect(fn () => $sessions->retainHistory($this->actor, new BackfillSessionRetention('studio')))->toThrow(RuntimeException::class, 'retention unavailable');
    expect(DB::table('backfill_references')->count())->toBe(0)->and(DB::table('music_project_retention_backfills')->count())->toBe(0);
    $fail = false;
    expect($sessions->retainHistory($this->actor, new BackfillSessionRetention('studio'))->complete())->toBeTrue();
    expect($sessions->retainHistory($this->actor, new BackfillSessionRetention('studio', restart: true))->processedRevisions)->toBe(2);
    expect(DB::table('backfill_references')->count())->toBe(2)->and(DB::table('music_project_revisions')->count())->toBe(2);
});
