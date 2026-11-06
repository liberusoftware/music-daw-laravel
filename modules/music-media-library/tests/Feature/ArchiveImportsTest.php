<?php

use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Events\Dispatcher as Events;
use Illuminate\Database\RecordNotFoundException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Liberu\Foundation\Files\Contracts\MalwareScanner;
use Liberu\Foundation\ImportExport\Contracts\TransferAuthorizer;
use Liberu\Music\MediaLibrary\Commands\RequestArchiveImport;
use Liberu\Music\MediaLibrary\Commands\SaveMediaPack;
use Liberu\Music\MediaLibrary\Commands\StartUpload;
use Liberu\Music\MediaLibrary\Events\ArchiveImportChanged;
use Liberu\Music\MediaLibrary\Events\AssetReady;
use Liberu\Music\MediaLibrary\Exceptions\ArchiveImportStopped;
use Liberu\Music\MediaLibrary\Exceptions\InvalidAudio;
use Liberu\Music\MediaLibrary\Exceptions\UploadConflict;
use Liberu\Music\MediaLibrary\Jobs\ImportPackArchiveTask;
use Liberu\Music\MediaLibrary\Services\ArchiveImports;
use Liberu\Music\MediaLibrary\Services\MediaPacks;
use Liberu\Music\MediaLibrary\Services\MediaUploads;
use Liberu\Music\MediaLibrary\Services\PackArchives;

beforeEach(function () {
    $this->artisan('migrate', ['--path' => realpath(__DIR__.'/../../database/migrations'), '--realpath' => true])->assertSuccessful();
    config()->set('music-media.disk', 'queued-archive-imports');
    $this->disk = Storage::fake('queued-archive-imports');
    $this->actor = new GenericUser(['id' => 1]);
    $this->denied = null;
    $this->allowOther = false;
    foreach (['upload', 'read', 'retain', 'pack_write', 'pack_read', 'pack_export', 'pack_import', 'distribute'] as $operation) {
        app(Gate::class)->define('music.media.'.$operation, fn ($actor, $workspace) => ($actor->id === 1 || $this->allowOther) && in_array($workspace, ['origin', 'studio', 'other']) && $this->denied !== $operation);
    }
    app(Gate::class)->define('music.media.pack_distribute', fn () => true);
    $this->allowed = true;
    app()->instance(TransferAuthorizer::class, Mockery::mock(TransferAuthorizer::class)->shouldReceive('allowed')->with(Mockery::any(), 'music-media-pack', Mockery::any())->andReturnUsing(fn () => $this->allowed)->getMock());
    $this->scanner = Mockery::mock(MalwareScanner::class);
    $this->scanner->shouldReceive('clean')->andReturnTrue()->byDefault();
    app()->instance(MalwareScanner::class, $this->scanner);
    $this->users = Mockery::mock(UserProvider::class)->shouldReceive('retrieveById')->with('1')->andReturn($this->actor)->byDefault()->getMock();
    app()->when(ArchiveImports::class)->needs(UserProvider::class)->give(fn () => $this->users);
    $this->uploads = app(MediaUploads::class);
    $rights = ['source' => 'Original takes', 'license' => 'CC-BY-4.0', 'attribution' => 'Artist'];
    $this->map = [];
    foreach ([1000, 2000] as $sample) {
        $format = pack('vvVVvv', 1, 1, 48000, 96000, 2, 16);
        $data = 'fmt '.pack('V', 16).$format.'data'.pack('V', 4).pack('v*', 0, $sample);
        $bytes = 'RIFF'.pack('V', strlen($data) + 4).'WAVE'.$data;
        $id = (string) Str::uuid();
        $this->uploads->start($this->actor, new StartUpload('origin', $id, 'Take '.$sample, strlen($bytes), hash('sha256', $bytes), $rights));
        $this->uploads->append($this->actor, 'origin', $id, 0, $bytes);
        $this->uploads->complete($this->actor, 'origin', $id);
        $this->map[$id] = (string) Str::uuid();
    }
    $origin = (string) Str::uuid();
    app(MediaPacks::class)->save($this->actor, new SaveMediaPack('origin', $origin, 'Originals', array_keys($this->map), $rights, 0, 'save'));
    $stream = tmpfile();
    app(PackArchives::class)->exportTo($this->actor, 'origin', $origin, $stream);
    rewind($stream);
    $this->zipBytes = stream_get_contents($stream);
    fclose($stream);
    $this->input = (string) Str::uuid();
    $this->uploads->start($this->actor, new StartUpload('studio', $this->input, 'Input.zip', strlen($this->zipBytes), hash('sha256', $this->zipBytes), purpose: 'archive'));
    $this->uploads->append($this->actor, 'studio', $this->input, 0, $this->zipBytes);
    $this->taskId = (string) Str::uuid();
    $this->pack = (string) Str::uuid();
    $this->command = fn (?string $task = null, ?array $map = null, ?string $input = null, ?string $pack = null) => new RequestArchiveImport('studio', $task ?? $this->taskId, $input ?? $this->input, $pack ?? $this->pack, $map ?? $this->map);
    $this->realBus = app(Dispatcher::class);
    Bus::fake();
    $this->jobs = app(ArchiveImports::class);
    $this->row = fn () => DB::table('music_media_archive_import_tasks')->where('id', $this->taskId)->firstOrFail();
    $this->job = fn () => new ImportPackArchiveTask($this->taskId, ($this->row)()->dispatch_id);
    $this->events = [];
    app(Events::class)->listen(ArchiveImportChanged::class, function ($event) {
        $this->events[] = $event;
    });
    $this->pauseWorker = function (): void {
        $pause = true;
        app(Events::class)->listen(ArchiveImportChanged::class, function ($event) use (&$pause) {
            if ($pause && $event->task->status === 'running') {
                $pause = false;
                throw new RuntimeException('Worker terminated after committed claim.');
            }
        });
        expect(fn () => ($this->job)()->handle($this->jobs))->toThrow(RuntimeException::class, 'terminated');
    };
});

it('queues after commit and publishes only a complete verified pack and task receipt together', function () {
    DB::transaction(function () {
        $task = $this->jobs->request($this->actor, ($this->command)());
        expect($task->jsonSerialize()['progress'])->toBe(['completed_steps' => 0, 'total_steps' => 2, 'unit' => 'processed_assets']);
        Bus::assertNothingDispatched();
        expect($this->events)->toBe([]);
    });
    Bus::assertDispatched(ImportPackArchiveTask::class);
    $old = ($this->job)();
    $this->jobs->request($this->actor, ($this->command)());
    $old->handle($this->jobs);
    expect($this->jobs->result($this->actor, 'studio', $this->taskId))->toBeNull();
    ($this->job)()->handle($this->jobs);
    $result = $this->jobs->result($this->actor, 'studio', $this->taskId);
    expect($result->packId)->toBe($this->pack)->and($result->version)->toBe(1)->and($result->assets)->toHaveCount(2)
        ->and($this->jobs->get($this->actor, 'studio', $this->taskId)->jsonSerialize())->toMatchArray(['status' => 'ready', 'terminal' => true, 'cancellable' => false, 'result_version' => 1]);
    expect($this->uploads->status($this->actor, 'studio', $this->input)->status)->toBe('cancelled')
        ->and($this->jobs->request($this->actor, ($this->command)())->status)->toBe('ready');
    $old->handle($this->jobs);
});

it('keeps verified upload progress durable when final publication acknowledgement fails', function () {
    $this->jobs->request($this->actor, ($this->command)());
    $job = ($this->job)();
    DB::unprepared("CREATE TRIGGER ignore_import_ack BEFORE UPDATE OF status ON music_media_archive_import_tasks WHEN NEW.status = 'ready' BEGIN SELECT RAISE(IGNORE); END");
    expect(fn () => $job->handle($this->jobs))->toThrow(LogicException::class);
    expect($this->jobs->get($this->actor, 'studio', $this->taskId)->status)->toBe('failed')
        ->and(DB::table('music_media_uploads')->where('workspace_id', 'studio')->where('status', 'ready')->count())->toBe(2)
        ->and(DB::table('music_media_packs')->where('id', $this->pack)->exists())->toBeFalse()
        ->and($this->uploads->status($this->actor, 'studio', $this->input)->status)->toBe('uploading');
    $job->failed(new RuntimeException('/private/path secret'));
    DB::unprepared('DROP TRIGGER ignore_import_ack');
    $this->jobs->request($this->actor, ($this->command)());
    ($this->job)()->handle($this->jobs);
    expect($this->jobs->result($this->actor, 'studio', $this->taskId)->version)->toBe(1)
        ->and(DB::table('music_media_pack_revisions')->where('pack_id', $this->pack)->count())->toBe(1);
});

it('resumes verified assets after a scanner failure without publishing an incomplete pack', function () {
    $waves = 0;
    $this->scanner->shouldReceive('clean')->andReturnUsing(function ($path) use (&$waves) {
        return substr(file_get_contents($path), 0, 4) !== 'RIFF' || ++$waves !== 2;
    });
    $this->jobs->request($this->actor, ($this->command)());
    expect(fn () => ($this->job)()->handle($this->jobs))->toThrow(InvalidAudio::class);
    $task = $this->jobs->get($this->actor, 'studio', $this->taskId);
    expect($task->completedAssets)->toBe(1)->and($task->jsonSerialize()['error_code'])->toBe('archive_import_failed')
        ->and($task->jsonSerialize()['retryable'])->toBeTrue();
    expect(DB::table('music_media_packs')->where('id', $this->pack)->exists())->toBeFalse();
    expect(fn () => $this->jobs->cancel($this->actor, 'studio', $this->taskId))->toThrow(UploadConflict::class);
    $this->scanner->shouldReceive('clean')->andReturnTrue();
    $this->jobs->request($this->actor, ($this->command)());
    ($this->job)()->handle($this->jobs);
    expect($this->jobs->get($this->actor, 'studio', $this->taskId)->attempt)->toBe(2);
});

it('cancels queued and running imports without deleting independently verified recordings', function () {
    $this->jobs->request($this->actor, ($this->command)());
    $job = ($this->job)();
    expect($this->jobs->cancel($this->actor, 'studio', $this->taskId)->status)->toBe('cancelled');
    $job->handle($this->jobs);
    expect($this->jobs->request($this->actor, ($this->command)())->status)->toBe('cancelled');
    $this->taskId = (string) Str::uuid();
    $this->jobs->request($this->actor, ($this->command)());
    app(Events::class)->listen(AssetReady::class, function ($event) {
        if ($event->asset->workspaceId === 'studio') {
            $task = $this->jobs->cancel($this->actor, 'studio', $this->taskId);
            expect($task->cancelRequested)->toBeTrue();
            $this->jobs->cancel($this->actor, 'studio', $this->taskId);
        }
    });
    ($this->job)()->handle($this->jobs);
    expect($this->jobs->get($this->actor, 'studio', $this->taskId)->status)->toBe('cancelled')
        ->and(DB::table('music_media_uploads')->where('workspace_id', 'studio')->where('status', 'ready')->count())->toBe(1)
        ->and(DB::table('music_media_packs')->where('id', $this->pack)->exists())->toBeFalse();
});

it('renews leases and prevents stale workers or failure callbacks from superseding a new attempt', function () {
    $this->jobs->request($this->actor, ($this->command)());
    ($this->pauseWorker)();
    $old = ($this->row)();
    $this->jobs->process($this->taskId, (string) Str::uuid());
    expect($this->jobs->request($this->actor, ($this->command)())->status)->toBe('running');
    $this->travel(46)->seconds();
    $this->jobs->checkpoint($this->taskId, $old->lease_nonce, 0, 2, 60);
    expect(CarbonImmutable::parse(($this->row)()->lease_expires_at)->greaterThan(CarbonImmutable::parse($old->lease_expires_at)))->toBeTrue();
    $this->travel(76)->seconds();
    expect($this->jobs->get($this->actor, 'studio', $this->taskId)->jsonSerialize()['error_code'])->toBe('archive_import_stalled');
    $this->jobs->request($this->actor, ($this->command)());
    $this->jobs->failed($this->taskId, $old->dispatch_id);
    expect(fn () => $this->jobs->checkpoint($this->taskId, $old->lease_nonce, 0, 2, 60))->toThrow(ArchiveImportStopped::class);
    expect(fn () => $this->jobs->publish($this->actor, $this->taskId, $old->lease_nonce, fn () => throw new RuntimeException('Must not publish')))->toThrow(ArchiveImportStopped::class);
    ($this->job)()->handle($this->jobs);
    $this->jobs->failed($this->taskId, $old->dispatch_id);
    expect($this->jobs->get($this->actor, 'studio', $this->taskId)->status)->toBe('ready');
    $this->travelBack();
});

it('enforces immutable commands owned inputs workspace policies and canonical worker identity', function () {
    $command = ($this->command)();
    $this->jobs->request($this->actor, $command);
    expect(fn () => $this->jobs->request($this->actor, ($this->command)(pack: (string) Str::uuid())))->toThrow(UploadConflict::class);
    expect(fn () => $this->jobs->get($this->actor, 'other', $this->taskId))->toThrow(RecordNotFoundException::class);
    $this->allowOther = true;
    expect(fn () => $this->jobs->get(new GenericUser(['id' => 2]), 'studio', $this->taskId))->toThrow(RecordNotFoundException::class);
    expect(fn () => $this->jobs->request(new GenericUser(['id' => 2]), ($this->command)((string) Str::uuid())))->toThrow(RecordNotFoundException::class);
    $this->allowed = false;
    expect(fn () => $this->jobs->get($this->actor, 'studio', $this->taskId))->toThrow(AuthorizationException::class);
    $this->allowed = true;
    $this->denied = 'retain';
    expect(fn () => ($this->job)()->handle($this->jobs))->toThrow(AuthorizationException::class);
    $this->denied = null;
    $this->jobs->request($this->actor, $command);
    $this->users->shouldReceive('retrieveById')->andReturnNull();
    expect(fn () => ($this->job)()->handle($this->jobs))->toThrow(AuthorizationException::class);
    $this->jobs->request($this->actor, $command);
    app()->when(ArchiveImports::class)->needs(UserProvider::class)->give(fn () => null);
    expect(fn () => ($this->job)()->handle(app(ArchiveImports::class)))->toThrow(AuthorizationException::class);
});

it('rejects invalid mappings missing bytes existing destination packs and invalid job configuration', function () {
    foreach ([[], ['invalid' => (string) Str::uuid()], [array_key_first($this->map) => $this->input]] as $map) {
        expect(fn () => ($this->command)(map: $map))->toThrow(ValidationException::class);
    }
    $partial = (string) Str::uuid();
    $this->uploads->start($this->actor, new StartUpload('studio', $partial, 'Partial', 44, str_repeat('a', 64), purpose: 'archive'));
    expect(fn () => $this->jobs->request($this->actor, ($this->command)(input: $partial)))->toThrow(ValidationException::class);
    $origin = DB::table('music_media_packs')->value('id');
    expect(fn () => $this->jobs->request($this->actor, ($this->command)(pack: $origin)))->toThrow(UploadConflict::class);
    config()->set('music-media.archive_import_timeout_seconds', 0);
    expect(fn () => $this->jobs->request($this->actor, ($this->command)()))->toThrow(LogicException::class);
    config()->set('music-media.archive_import_timeout_seconds', 60);
    $this->jobs->request($this->actor, ($this->command)());
    expect(fn () => $this->jobs->process($this->taskId, (string) Str::uuid(), 59))->toThrow(LogicException::class);
    expect(fn () => $this->jobs->checkpoint($this->taskId, 'x', 3, 2, 60))->toThrow(LogicException::class);
    $job = ($this->job)();
    $job->failed(new RuntimeException('Dispatch could not start'));
    expect($this->jobs->get($this->actor, 'studio', $this->taskId)->status)->toBe('failed');
    $this->jobs->failed((string) Str::uuid(), (string) Str::uuid());
});

it('acknowledges an abandoned cancellation through replay or a recovered worker', function (string $action) {
    $this->jobs->request($this->actor, ($this->command)());
    ($this->pauseWorker)();
    $job = ($this->job)();
    expect($this->jobs->cancel($this->actor, 'studio', $this->taskId)->status)->toBe('running');
    $this->travel(76)->seconds();
    match ($action) {
        'replay' => $this->jobs->request($this->actor, ($this->command)()),
        'worker' => $job->handle($this->jobs),
        'cancel' => $this->jobs->cancel($this->actor, 'studio', $this->taskId),
    };
    expect($this->jobs->get($this->actor, 'studio', $this->taskId)->status)->toBe('cancelled')
        ->and(DB::table('music_media_packs')->where('id', $this->pack)->exists())->toBeFalse();
    $this->travelBack();
})->with(['replay', 'worker', 'cancel']);

it('rechecks input cancellation at the publication boundary after every asset is verified', function () {
    $this->jobs->request($this->actor, ($this->command)());
    app(Events::class)->listen(ArchiveImportChanged::class, function ($event) {
        if ($event->task->status === 'running' && $event->task->completedAssets === 2) {
            $this->uploads->cancel($this->actor, 'studio', $this->input);
        }
    });
    ($this->job)()->handle($this->jobs);
    expect($this->jobs->get($this->actor, 'studio', $this->taskId)->status)->toBe('cancelled')
        ->and(DB::table('music_media_packs')->where('id', $this->pack)->exists())->toBeFalse()
        ->and(DB::table('music_media_uploads')->where('workspace_id', 'studio')->where('status', 'ready')->count())->toBe(2);
});

it('rejects corrupted progress totals and mismatched publication identities without consuming input', function () {
    $this->jobs->request($this->actor, ($this->command)());
    ($this->pauseWorker)();
    $row = ($this->row)();
    $origin = DB::table('music_media_packs')->value('id');
    $original = app(MediaPacks::class)->get($this->actor, 'origin', $origin);
    expect(fn () => $this->jobs->publish(new GenericUser(['id' => 2]), $this->taskId, $row->lease_nonce, fn () => $original))->toThrow(AuthorizationException::class);
    expect(fn () => $this->jobs->publish($this->actor, $this->taskId, $row->lease_nonce, fn () => $original))->toThrow(LogicException::class, 'differs');
    expect($this->uploads->status($this->actor, 'studio', $this->input)->status)->toBe('uploading');
    $this->jobs->failed($this->taskId, $row->dispatch_id);
    $this->jobs->request($this->actor, ($this->command)());
    DB::table('music_media_archive_import_tasks')->where('id', $this->taskId)->update(['total_assets' => 3]);
    expect(fn () => ($this->job)()->handle($this->jobs))->toThrow(LogicException::class, 'immutable mapping');
    expect($this->jobs->get($this->actor, 'studio', $this->taskId)->status)->toBe('failed');
});

it('keeps a committed queued import recoverable after dispatch failure and suppresses stale callbacks', function () {
    app()->instance(Dispatcher::class, Mockery::mock(Dispatcher::class)->shouldReceive('dispatch')->andThrow(new RuntimeException('queue unavailable'))->getMock());
    expect(fn () => app(ArchiveImports::class)->request($this->actor, ($this->command)()))->toThrow(RuntimeException::class, 'queue unavailable');
    $stale = ($this->job)();
    app()->instance(Dispatcher::class, $this->realBus);
    Bus::fake();
    $this->jobs = app(ArchiveImports::class);
    $this->jobs->request($this->actor, ($this->command)());
    $stale->failed(new RuntimeException('old dispatch'));
    expect($this->jobs->get($this->actor, 'studio', $this->taskId)->status)->toBe('queued');
    ($this->job)()->handle($this->jobs);
    expect($this->jobs->result($this->actor, 'studio', $this->taskId)->version)->toBe(1);
});

it('runs on the real synchronous queue and rolls back only task metadata', function () {
    app()->instance(Dispatcher::class, $this->realBus);
    app(ArchiveImports::class)->request($this->actor, ($this->command)());
    expect(app(ArchiveImports::class)->get($this->actor, 'studio', $this->taskId)->status)->toBe('ready');
    $migration = require __DIR__.'/../../database/migrations/2026_11_06_004105_create_music_media_archive_import_tasks.php';
    $migration->down();
    expect(Schema::hasTable('music_media_archive_import_tasks'))->toBeFalse()
        ->and(DB::table('music_media_uploads')->whereIn('id', array_values($this->map))->where('status', 'ready')->count())->toBe(2)
        ->and(DB::table('music_media_packs')->where('id', $this->pack)->exists())->toBeTrue();
});

it('allows one active consumer per input while allowing reuse after an abandoned task', function () {
    $first = ($this->command)();
    $this->jobs->request($this->actor, $first);
    $second = (string) Str::uuid();
    expect(fn () => $this->jobs->request($this->actor, ($this->command)($second)))->toThrow(UploadConflict::class, 'active import task');
    expect(DB::table('music_media_archive_import_tasks')->where('id', $second)->exists())->toBeFalse();
    $this->jobs->cancel($this->actor, 'studio', $this->taskId);
    $this->taskId = $second;
    $this->jobs->request($this->actor, ($this->command)());
    ($this->job)()->handle($this->jobs);
    expect($this->jobs->request($this->actor, $first)->status)->toBe('cancelled')
        ->and($this->jobs->get($this->actor, 'studio', $this->taskId)->status)->toBe('ready');
});

it('refuses a caller transaction which would hide independently durable import progress', function () {
    $this->jobs->request($this->actor, ($this->command)());
    DB::transaction(function () {
        expect(fn () => ($this->job)()->handle($this->jobs))->toThrow(LogicException::class, 'independently committed');
    });
    expect($this->jobs->get($this->actor, 'studio', $this->taskId)->status)->toBe('queued');
});

it('protects a newer lease and lets a pending cancellation win when an old worker fails', function (string $mode) {
    $this->jobs->request($this->actor, ($this->command)());
    $old = ($this->job)();
    $fault = true;
    app(Events::class)->listen(AssetReady::class, function ($event) use (&$fault, $mode) {
        if ($fault && $event->asset->workspaceId === 'studio') {
            $fault = false;
            if ($mode === 'new lease') {
                $this->travel(76)->seconds();
                $this->jobs->request($this->actor, ($this->command)());
                ($this->pauseWorker)();
            } else {
                $this->jobs->cancel($this->actor, 'studio', $this->taskId);
            }
            throw new RuntimeException('/private old worker fault');
        }
    });
    expect(fn () => $old->handle($this->jobs))->toThrow(RuntimeException::class, 'old worker fault');
    $old->failed(new RuntimeException('late callback'));
    $task = $this->jobs->get($this->actor, 'studio', $this->taskId);
    expect($task->status)->toBe($mode === 'new lease' ? 'running' : 'cancelled');
    expect(json_encode($task))->not->toContain('/private', 'old worker fault', 'lease_nonce', 'dispatch_id');
    if ($mode === 'new lease') {
        $this->travel(76)->seconds();
        $this->jobs->request($this->actor, ($this->command)());
        ($this->job)()->handle($this->jobs);
        expect($this->jobs->result($this->actor, 'studio', $this->taskId)->version)->toBe(1);
        $this->travelBack();
    }
})->with(['new lease', 'cancel']);
