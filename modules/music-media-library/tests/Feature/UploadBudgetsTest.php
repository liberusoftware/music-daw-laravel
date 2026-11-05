<?php

use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Database\RecordNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Liberu\Foundation\Files\Contracts\MalwareScanner;
use Liberu\Music\MediaLibrary\Commands\CollectCancelledUploads;
use Liberu\Music\MediaLibrary\Commands\StartUpload;
use Liberu\Music\MediaLibrary\Contracts\MediaAuthorizer;
use Liberu\Music\MediaLibrary\Contracts\UploadBudgetPolicy;
use Liberu\Music\MediaLibrary\Events\UploadBudgetChanged;
use Liberu\Music\MediaLibrary\Exceptions\UploadBudgetExceeded;
use Liberu\Music\MediaLibrary\Exceptions\UploadConflict;
use Liberu\Music\MediaLibrary\Services\MediaUploads;
use Liberu\Music\MediaLibrary\Services\QuarantineCleanup;
use Liberu\Music\MediaLibrary\Services\UploadBudgets;

beforeEach(function () {
    $this->artisan('migrate', ['--path' => realpath(__DIR__.'/../../database/migrations'), '--realpath' => true])->assertSuccessful();
    $this->travelTo(CarbonImmutable::parse('2026-11-05T12:00:00Z'));
    config()->set('music-media.disk', 'upload-budgets-test');
    config()->set('music-media.max_reserved_bytes', 96);
    $this->disk = Storage::fake('upload-budgets-test');
    $this->actor = new GenericUser(['id' => 1]);
    foreach (['upload', 'read', 'collect', 'quota_read'] as $operation) {
        app(Gate::class)->define('music.media.'.$operation, fn ($actor, $workspace) => $actor->id === 1 && in_array($workspace, ['studio', 'other']));
    }
    $scanner = Mockery::mock(MalwareScanner::class);
    $scanner->shouldReceive('clean')->andReturn(true);
    app()->instance(MalwareScanner::class, $scanner);
    $format = pack('vvVVvv', 1, 1, 48000, 96000, 2, 16);
    $data = 'fmt '.pack('V', 16).$format.'data'.pack('V', 4).pack('v*', 0, 16384);
    $this->bytes = 'RIFF'.pack('V', strlen($data) + 4).'WAVE'.$data;
    $this->uploads = app(MediaUploads::class);
    $this->budgets = app(UploadBudgets::class);
    $this->cleanup = app(QuarantineCleanup::class);
    $this->command = fn (string $workspace = 'studio'): StartUpload => new StartUpload($workspace, (string) Str::uuid(), 'Take', strlen($this->bytes), hash('sha256', $this->bytes));
    $this->events = [];
    app(Dispatcher::class)->listen(UploadBudgetChanged::class, function ($event) {
        $this->events[] = $event;
    });
});

it('atomically reserves quarantine and verified capacity and returns a scoped versioned budget document', function () {
    expect($this->budgets->get($this->actor, 'studio')->jsonSerialize())->toBe([
        'schema_version' => 1, 'workspace_id' => 'studio', 'reserved_bytes' => 0, 'limit_bytes' => 96,
        'available_bytes' => 96, 'over_limit' => false, 'reservation_multiplier' => 2,
    ]);
    expect(DB::table('music_media_workspace_usage')->count())->toBe(0);
    $command = ($this->command)();
    $this->uploads->start($this->actor, $command);
    expect($this->budgets->get($this->actor, 'studio')->reservedBytes)->toBe(96)
        ->and($this->budgets->get($this->actor, 'studio')->availableBytes())->toBe(0)
        ->and($this->events)->toHaveCount(1)->and($this->events[0]->deltaBytes)->toBe(96)
        ->and($this->events[0]->reservedBytes)->toBe(96)->and($this->events[0]->uploadIds)->toBe([$command->uploadId])
        ->and($this->events[0]->actorId)->toBe('1');
    try {
        $this->uploads->start($this->actor, ($this->command)());
        $this->fail('The second reservation must not exceed the workspace limit.');
    } catch (UploadBudgetExceeded $error) {
        expect($error->requestedBytes)->toBe(96)->and($error->budget->workspaceId)->toBe('studio')->and($error->budget->availableBytes())->toBe(0);
    }
    expect(DB::table('music_media_uploads')->count())->toBe(1)
        ->and(DB::table('music_media_upload_reservations')->count())->toBe(1)->and($this->events)->toHaveCount(1);
});

it('replays reservations once and allows accepted uploads to finish after the budget is lowered', function () {
    $command = ($this->command)();
    $this->uploads->start($this->actor, $command);
    config()->set('music-media.max_reserved_bytes', 32);
    $this->uploads->start($this->actor, $command);
    DB::transaction(fn () => $this->budgets->reserveInTransaction($this->actor, 'studio', $command->uploadId, DB::connection()));
    expect($this->events)->toHaveCount(1)->and($this->budgets->get($this->actor, 'studio')->overLimit())->toBeTrue()
        ->and($this->budgets->get($this->actor, 'studio')->availableBytes())->toBe(0);
    $this->uploads->append($this->actor, 'studio', $command->uploadId, 0, $this->bytes);
    expect($this->uploads->complete($this->actor, 'studio', $command->uploadId)->id)->toBe($command->uploadId);
    expect(fn () => $this->uploads->start($this->actor, ($this->command)()))->toThrow(UploadBudgetExceeded::class);
    $released = DB::transaction(fn () => $this->budgets->releaseCancelledInTransaction($this->actor, 'studio', [$command->uploadId], DB::connection()));
    expect($released)->toBe(0)->and($this->budgets->get($this->actor, 'studio')->reservedBytes)->toBe(96);
});

it('releases unused cancellation capacity once without reviving cancelled uploads on start replay', function () {
    $command = ($this->command)();
    $this->uploads->start($this->actor, $command);
    $this->uploads->cancel($this->actor, 'studio', $command->uploadId);
    $this->uploads->cancel($this->actor, 'studio', $command->uploadId);
    expect($this->uploads->start($this->actor, $command)->status)->toBe('cancelled')
        ->and($this->budgets->get($this->actor, 'studio')->reservedBytes)->toBe(0)->and($this->events)->toHaveCount(2)
        ->and($this->events[1]->deltaBytes)->toBe(-96)->and($this->events[1]->reservedBytes)->toBe(0)
        ->and(DB::table('music_media_upload_reservations')->value('released_at'))->toBe('2026-11-05 12:00:00');
    $this->uploads->start($this->actor, ($this->command)());
    expect($this->budgets->get($this->actor, 'studio')->reservedBytes)->toBe(96);
});

it('holds cancelled reservations through grace periods dry runs and partial cleanup pages', function () {
    $command = ($this->command)();
    $this->uploads->start($this->actor, $command);
    $this->uploads->append($this->actor, 'studio', $command->uploadId, 0, substr($this->bytes, 0, 24));
    $this->uploads->append($this->actor, 'studio', $command->uploadId, 24, substr($this->bytes, 24));
    $this->uploads->cancel($this->actor, 'studio', $command->uploadId);
    expect(fn () => $this->uploads->start($this->actor, ($this->command)()))->toThrow(UploadBudgetExceeded::class);
    $this->cleanup->collect($this->actor, new CollectCancelledUploads('studio', dryRun: false));
    expect($this->budgets->get($this->actor, 'studio')->reservedBytes)->toBe(96);
    $this->travel(24)->hours();
    $this->cleanup->collect($this->actor, new CollectCancelledUploads('studio'));
    $first = $this->cleanup->collect($this->actor, new CollectCancelledUploads('studio', limit: 1, dryRun: false));
    expect($this->budgets->get($this->actor, 'studio')->reservedBytes)->toBe(96)->and($this->events)->toHaveCount(1);
    $this->cleanup->collect($this->actor, new CollectCancelledUploads('studio', afterChunkId: $first->nextChunkId, dryRun: false));
    $this->cleanup->collect($this->actor, new CollectCancelledUploads('studio', dryRun: false));
    expect($this->budgets->get($this->actor, 'studio')->reservedBytes)->toBe(0)->and($this->events)->toHaveCount(2)
        ->and(DB::table('music_media_uploads')->value('status'))->toBe('cancelled');
});

it('does not free budget when storage deletion fails and recovers quota after a database rollback', function () {
    $command = ($this->command)();
    $this->uploads->start($this->actor, $command);
    $this->uploads->append($this->actor, 'studio', $command->uploadId, 0, $this->bytes);
    $this->uploads->cancel($this->actor, 'studio', $command->uploadId);
    $this->travel(24)->hours();
    $objects = Mockery::mock(Filesystem::class);
    $objects->shouldReceive('exists')->andReturn(true);
    $objects->shouldReceive('delete')->once()->andReturn(false);
    $cleanup = new QuarantineCleanup(DB::connection(), $objects, app(MediaAuthorizer::class), app(Dispatcher::class), $this->budgets);
    expect(fn () => $cleanup->collect($this->actor, new CollectCancelledUploads('studio', dryRun: false)))->toThrow(RuntimeException::class);
    expect($this->budgets->get($this->actor, 'studio')->reservedBytes)->toBe(96);
    DB::beginTransaction();
    $this->cleanup->collect($this->actor, new CollectCancelledUploads('studio', dryRun: false));
    expect($this->budgets->get($this->actor, 'studio')->reservedBytes)->toBe(0)->and($this->events)->toHaveCount(1);
    DB::rollBack();
    expect($this->budgets->get($this->actor, 'studio')->reservedBytes)->toBe(96)->and($this->events)->toHaveCount(1);
    $this->cleanup->collect($this->actor, new CollectCancelledUploads('studio', dryRun: false));
    expect($this->budgets->get($this->actor, 'studio')->reservedBytes)->toBe(0)->and($this->events)->toHaveCount(2);
});

it('isolates workspace accounting and supports an authoritative host entitlement policy', function () {
    $policy = Mockery::mock(UploadBudgetPolicy::class);
    $policy->shouldReceive('maximumReservedBytes')->with($this->actor, 'studio')->andReturn(96);
    $policy->shouldReceive('maximumReservedBytes')->with($this->actor, 'other')->andReturn(192);
    app()->instance(UploadBudgetPolicy::class, $policy);
    $uploads = app(MediaUploads::class);
    $budgets = app(UploadBudgets::class);
    $studio = ($this->command)();
    $uploads->start($this->actor, $studio);
    $uploads->start($this->actor, ($this->command)('other'));
    $uploads->start($this->actor, ($this->command)('other'));
    expect($budgets->get($this->actor, 'studio')->reservedBytes)->toBe(96)
        ->and($budgets->get($this->actor, 'other')->reservedBytes)->toBe(192);
    expect(fn () => $uploads->start($this->actor, ($this->command)()))->toThrow(UploadBudgetExceeded::class);
    expect(fn () => DB::transaction(fn () => $budgets->reserveInTransaction($this->actor, 'other', $studio->uploadId, DB::connection())))->toThrow(RecordNotFoundException::class);
    expect(DB::transaction(fn () => $budgets->releaseCancelledInTransaction($this->actor, 'other', [$studio->uploadId], DB::connection())))->toBe(0);
});

it('authorizes budget reads and upload allocation with the supplied actor and workspace', function () {
    $other = new GenericUser(['id' => 2]);
    expect(fn () => $this->budgets->get($other, 'studio'))->toThrow(AuthorizationException::class);
    expect(fn () => $this->budgets->get($this->actor, 'wrong'))->toThrow(AuthorizationException::class);
    expect(fn () => $this->uploads->start($other, ($this->command)()))->toThrow(AuthorizationException::class);
    expect(DB::table('music_media_workspace_usage')->count())->toBe(0)->and(DB::table('music_media_uploads')->count())->toBe(0);
});

it('requires the exact active upload transaction for reservation changes', function () {
    $command = ($this->command)();
    $foreign = Mockery::mock(ConnectionInterface::class);
    foreach ([DB::connection(), $foreign] as $connection) {
        expect(fn () => $this->budgets->reserveInTransaction($this->actor, 'studio', $command->uploadId, $connection))->toThrow(LogicException::class, 'transaction');
        expect(fn () => $this->budgets->releaseCancelledInTransaction($this->actor, 'studio', [], $connection))->toThrow(LogicException::class, 'transaction');
    }
});

it('keeps reservation events and metadata atomic when an enclosing transaction rolls back', function () {
    DB::beginTransaction();
    $this->uploads->start($this->actor, ($this->command)());
    expect($this->events)->toBe([])->and($this->budgets->get($this->actor, 'studio')->reservedBytes)->toBe(96);
    DB::rollBack();
    expect($this->events)->toBe([])->and($this->budgets->get($this->actor, 'studio')->reservedBytes)->toBe(0)
        ->and(DB::table('music_media_upload_reservations')->count())->toBe(0)->and(DB::table('music_media_uploads')->count())->toBe(0);
});

it('rolls back refused counter updates and reservation insert failures without consuming budget', function (bool $counterFailure) {
    DB::unprepared($counterFailure
        ? 'CREATE TRIGGER refuse_budget BEFORE UPDATE ON music_media_workspace_usage BEGIN SELECT RAISE(IGNORE); END'
        : "CREATE TRIGGER refuse_budget BEFORE INSERT ON music_media_upload_reservations BEGIN SELECT RAISE(ABORT, 'reservation failed'); END");
    expect(fn () => $this->uploads->start($this->actor, ($this->command)()))->toThrow($counterFailure ? LogicException::class : QueryException::class);
    expect(DB::table('music_media_uploads')->count())->toBe(0)->and(DB::table('music_media_upload_reservations')->count())->toBe(0)
        ->and(DB::table('music_media_workspace_usage')->count())->toBe(0)->and($this->events)->toBe([]);
})->with([true, false]);

it('refuses counter overflow and underflow rather than silently corrupting allocation', function (bool $overflow) {
    $command = ($this->command)();
    $this->uploads->start($this->actor, $command);
    DB::table('music_media_workspace_usage')->update(['reserved_bytes' => $overflow ? PHP_INT_MAX : 0]);
    $operation = $overflow ? fn () => $this->uploads->start($this->actor, ($this->command)()) : fn () => $this->uploads->cancel($this->actor, 'studio', $command->uploadId);
    expect($operation)->toThrow(LogicException::class);
    expect(DB::table('music_media_uploads')->count())->toBe(1)->and(DB::table('music_media_uploads')->value('status'))->toBe('uploading')
        ->and(DB::table('music_media_upload_reservations')->value('reserved_bytes'))->toBe(96)->and($this->events)->toHaveCount(1);
})->with([true, false]);

it('allows unlimited reservations by explicit policy and permits cleanup during configuration failure', function () {
    config()->set('music-media.max_reserved_bytes', null);
    $first = ($this->command)();
    $this->uploads->start($this->actor, $first);
    $this->uploads->start($this->actor, ($this->command)());
    $budget = $this->budgets->get($this->actor, 'studio');
    expect($budget->reservedBytes)->toBe(192)->and($budget->availableBytes())->toBeNull()->and($budget->overLimit())->toBeFalse();
    config()->set('music-media.max_reserved_bytes', 'invalid');
    $this->uploads->cancel($this->actor, 'studio', $first->uploadId);
    expect(DB::table('music_media_workspace_usage')->value('reserved_bytes'))->toBe(96);
});

it('rejects invalid configured byte limits without allocating uploads', function (mixed $value) {
    config()->set('music-media.max_reserved_bytes', $value);
    expect(fn () => $this->uploads->start($this->actor, ($this->command)()))->toThrow(ValidationException::class);
    expect(DB::table('music_media_uploads')->count())->toBe(0);
})->with([[-1], [true], [false], [1.5], ['invalid'], ['1e5'], ['9223372036854775808']]);

it('accepts decimal environment limits and zero as an explicit no new allocation policy', function () {
    config()->set('music-media.max_reserved_bytes', '96');
    $command = ($this->command)();
    $this->uploads->start($this->actor, $command);
    config()->set('music-media.max_reserved_bytes', '0');
    expect(fn () => $this->uploads->start($this->actor, ($this->command)()))->toThrow(UploadBudgetExceeded::class);
    expect($this->uploads->start($this->actor, $command)->status)->toBe('uploading');
});

it('rejects invalid host policy contracts', function () {
    $policy = Mockery::mock(UploadBudgetPolicy::class);
    $policy->shouldReceive('maximumReservedBytes')->andReturn(-1);
    app()->instance(UploadBudgetPolicy::class, $policy);
    expect(fn () => app(MediaUploads::class)->start($this->actor, ($this->command)()))->toThrow(LogicException::class, 'non-negative');
    expect(DB::table('music_media_uploads')->count())->toBe(0);
});

it('backfills live and cancelled quarantine reservations and reverses accounting without deleting audio', function () {
    config()->set('music-media.max_reserved_bytes', null);
    $active = ($this->command)();
    $ready = ($this->command)();
    $cancelled = ($this->command)();
    $unused = ($this->command)();
    foreach ([$active, $ready, $cancelled, $unused] as $command) {
        $this->uploads->start($this->actor, $command);
    }
    foreach ([$ready, $cancelled] as $command) {
        $this->uploads->append($this->actor, 'studio', $command->uploadId, 0, $this->bytes);
    }
    $this->uploads->complete($this->actor, 'studio', $ready->uploadId);
    $this->uploads->cancel($this->actor, 'studio', $cancelled->uploadId);
    $this->uploads->cancel($this->actor, 'studio', $unused->uploadId);
    $this->artisan('migrate:rollback', ['--path' => realpath(__DIR__.'/../../database/migrations'), '--realpath' => true,
        '--step' => DB::table('migrations')->where('migration', '>=', '2026_11_05_205131_create_music_media_upload_budgets')->count()])->assertSuccessful();
    expect(Schema::hasTable('music_media_workspace_usage'))->toBeFalse()->and(DB::table('music_media_uploads')->count())->toBe(4);
    $this->artisan('migrate', ['--path' => realpath(__DIR__.'/../../database/migrations'), '--realpath' => true])->assertSuccessful();
    config()->set('music-media.max_reserved_bytes', 96);
    expect($this->budgets->get($this->actor, 'studio')->reservedBytes)->toBe(288)
        ->and($this->budgets->get($this->actor, 'studio')->overLimit())->toBeTrue()
        ->and(DB::table('music_media_upload_reservations')->count())->toBe(3)
        ->and($this->uploads->get($this->actor, 'studio', $ready->uploadId)->id)->toBe($ready->uploadId);
    expect(fn () => $this->uploads->start($this->actor, ($this->command)()))->toThrow(UploadBudgetExceeded::class);
    expect($this->uploads->start($this->actor, $active)->status)->toBe('uploading');
});

it('reads a workspace budget with one owned query and no actor history disclosure', function () {
    $this->uploads->start($this->actor, ($this->command)());
    DB::enableQueryLog();
    DB::flushQueryLog();
    $budget = $this->budgets->get($this->actor, 'studio');
    expect(DB::getQueryLog())->toHaveCount(1)->and(json_encode($budget))->not->toContain('actor_id', 'upload_id', 'blob_key');
    DB::disableQueryLog();
});

it('reserves batch declarations atomically and preserves caller order on replay', function () {
    $commands = [($this->command)(), ($this->command)()];
    expect(fn () => $this->uploads->startBatch($this->actor, $commands))->toThrow(UploadBudgetExceeded::class);
    expect(DB::table('music_media_uploads')->count())->toBe(0)->and(DB::table('music_media_workspace_usage')->count())->toBe(0)->and($this->events)->toBe([]);
    config()->set('music-media.max_reserved_bytes', 192);
    $progress = $this->uploads->startBatch($this->actor, $commands);
    expect(array_column($progress, 'id'))->toBe(array_column($commands, 'uploadId'))
        ->and($this->budgets->get($this->actor, 'studio')->reservedBytes)->toBe(192)->and($this->events)->toHaveCount(2);
    $this->uploads->startBatch($this->actor, $commands);
    expect($this->events)->toHaveCount(2);
});

it('validates batch membership and refuses cancelled declarations before committing new reservations', function () {
    $command = ($this->command)();
    foreach ([[], [null], [$command, $command], array_fill(0, 1001, $command)] as $commands) {
        expect(fn () => $this->uploads->startBatch($this->actor, $commands))->toThrow(ValidationException::class);
    }
    $this->uploads->start($this->actor, $command);
    $this->uploads->cancel($this->actor, 'studio', $command->uploadId);
    expect(fn () => $this->uploads->startBatch($this->actor, [($this->command)(), $command]))->toThrow(UploadConflict::class);
    expect(DB::table('music_media_uploads')->count())->toBe(1)->and($this->budgets->get($this->actor, 'studio')->reservedBytes)->toBe(0);
});
