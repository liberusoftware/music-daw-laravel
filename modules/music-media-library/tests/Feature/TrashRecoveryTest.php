<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Events\Dispatcher as Events;
use Illuminate\Database\RecordNotFoundException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Liberu\Foundation\Files\Contracts\MalwareScanner;
use Liberu\Music\MediaLibrary\Commands\RequestTrashRecovery;
use Liberu\Music\MediaLibrary\Commands\StartUpload;
use Liberu\Music\MediaLibrary\Commands\TrashAssets;
use Liberu\Music\MediaLibrary\Events\TrashRecoveryChanged;
use Liberu\Music\MediaLibrary\Exceptions\InvalidAudio;
use Liberu\Music\MediaLibrary\Exceptions\UploadConflict;
use Liberu\Music\MediaLibrary\Jobs\RecoverTrashTask;
use Liberu\Music\MediaLibrary\Services\AssetLifecycle;
use Liberu\Music\MediaLibrary\Services\MediaUploads;
use Liberu\Music\MediaLibrary\Services\TrashRecovery;

beforeEach(function () {
    $this->artisan('migrate', ['--path' => realpath(__DIR__.'/../../database/migrations'), '--realpath' => true])->assertSuccessful();
    config()->set('music-media.disk', 'asset-processing');
    $this->disk = Storage::fake('asset-processing');
    $this->actor = new GenericUser(['id' => 7]);
    $this->denied = null;
    foreach (['upload', 'read', 'restore', 'retain', 'trash'] as $operation) {
        app(Gate::class)->define('music.media.'.$operation, fn ($actor, $workspace) => $actor->id === 7 && in_array($workspace, ['studio', 'other']) && $this->denied !== $operation);
    }
    $this->scanner = Mockery::mock(MalwareScanner::class);
    $this->scanClean = true;
    $this->scanner->shouldReceive('clean')->andReturnUsing(fn () => $this->scanClean)->byDefault();
    app()->instance(MalwareScanner::class, $this->scanner);
    $this->users = Mockery::mock(UserProvider::class);
    $this->users->shouldReceive('retrieveById')->with('7')->andReturn($this->actor)->byDefault();
    app()->when(TrashRecovery::class)->needs(UserProvider::class)->give(fn () => $this->users);
    $this->realBus = app(Dispatcher::class);
    Bus::fake();
    $format = pack('vvVVvv', 1, 1, 48000, 96000, 2, 16);
    $chunks = 'fmt '.pack('V', 16).$format.'data'.pack('V', 4).pack('v*', 0, 16384);
    $this->bytes = 'RIFF'.pack('V', strlen($chunks) + 4).'WAVE'.$chunks;
    $this->uploads = app(MediaUploads::class);
    $this->jobs = app(TrashRecovery::class);
    $this->upload = function (bool $complete = true, string $workspace = 'studio', ?string $bytes = null): string {
        $id = (string) Str::uuid();
        $bytes ??= $this->bytes;
        $this->uploads->start($this->actor, new StartUpload($workspace, $id, 'Recording', strlen($bytes), hash('sha256', $bytes), []));
        if ($complete) {
            $this->uploads->append($this->actor, $workspace, $id, 0, $bytes);
        }

        return $id;
    };
    $this->id = ($this->upload)();
    $this->uploads->complete($this->actor, 'studio', $this->id);
    app(AssetLifecycle::class)->trash($this->actor, new TrashAssets('studio', [$this->id], 'trash'));
    $this->command = fn (?string $id = null, ?string $task = null) => new RequestTrashRecovery('studio', $task ?? (string) Str::uuid(), $id ?? $this->id);
    $this->events = [];
    app(Events::class)->listen(TrashRecoveryChanged::class, function ($event) {
        $this->events[] = $event;
    });
});

it('queues after commit and recovers the original verified identity with safe public task metadata', function () {
    $command = ($this->command)();
    DB::transaction(function () use ($command) {
        $task = $this->jobs->request($this->actor, $command);
        expect($task->jsonSerialize()['progress']['completed_steps'])->toBe(0)->and($this->events)->toBe([]);
        Bus::assertNothingDispatched();
    });
    Bus::assertDispatched(RecoverTrashTask::class, fn ($job) => $job->taskId === $command->taskId);
    expect($this->events)->toHaveCount(1)->and($this->jobs->result($this->actor, 'studio', $command->taskId))->toBeNull();
    $this->jobs->request($this->actor, $command);
    expect($this->events)->toHaveCount(1);
    Bus::dispatched(RecoverTrashTask::class)->last()->handle($this->jobs);
    $this->jobs->process($command->taskId);
    expect($this->jobs->get($this->actor, 'studio', $command->taskId)->jsonSerialize())->toMatchArray(['kind' => 'recover_trash', 'status' => 'ready', 'terminal' => true, 'cancellable' => false, 'error_code' => null])
        ->and($this->jobs->result($this->actor, 'studio', $command->taskId)->id)->toBe($this->id)
        ->and($this->jobs->request($this->actor, $command)->status)->toBe('ready')->and($this->events)->toHaveCount(2);
    expect(fn () => $this->jobs->cancel($this->actor, 'studio', $command->taskId))->toThrow(UploadConflict::class);
    $this->jobs->failed($command->taskId);
    $this->disk->delete('music/assets/'.hash('sha256', 'studio').'/'.hash('sha256', $this->bytes));
    expect(fn () => $this->jobs->result($this->actor, 'studio', $command->taskId))->toThrow(InvalidAudio::class);
});

it('serializes cancellation with recovery and never reopens a cancelled task', function () {
    $command = ($this->command)();
    $this->jobs->request($this->actor, $command);
    expect($this->jobs->cancel($this->actor, 'studio', $command->taskId)->status)->toBe('cancelled')
        ->and($this->jobs->cancel($this->actor, 'studio', $command->taskId)->status)->toBe('cancelled');
    $this->jobs->process($command->taskId);
    $this->jobs->failed($command->taskId);
    expect($this->jobs->request($this->actor, $command)->status)->toBe('cancelled')
        ->and(DB::table('music_media_uploads')->where('id', $this->id)->value('status'))->toBe('trashed');
});

it('enforces owned immutable task identities live policies and valid trash state', function () {
    $command = ($this->command)();
    $this->jobs->request($this->actor, $command);
    $other = ($this->upload)();
    expect(fn () => $this->jobs->request($this->actor, ($this->command)($other, $command->taskId)))->toThrow(UploadConflict::class);
    expect(fn () => $this->jobs->request($this->actor, ($this->command)($other)))->toThrow(UploadConflict::class);
    expect(fn () => $this->jobs->get($this->actor, 'other', $command->taskId))->toThrow(RecordNotFoundException::class);
    expect(fn () => $this->jobs->get(new GenericUser(['id' => 8]), 'studio', $command->taskId))->toThrow(RecordNotFoundException::class);
    $this->denied = 'restore';
    expect(fn () => $this->jobs->get($this->actor, 'studio', $command->taskId))->toThrow(AuthorizationException::class);
    expect(fn () => $this->jobs->cancel($this->actor, 'studio', $command->taskId))->toThrow(AuthorizationException::class);
    expect(fn () => $this->jobs->process($command->taskId))->toThrow(AuthorizationException::class);
    expect(fn () => $this->jobs->request($this->actor, ($this->command)()))->toThrow(AuthorizationException::class);
    $this->denied = null;
    expect(fn () => new RequestTrashRecovery('', 'bad', 'bad'))->toThrow(ValidationException::class);
});

it('fails safely on corrupt media and retries the same recovery command without exposing errors', function () {
    $command = ($this->command)();
    $this->jobs->request($this->actor, $command);
    $key = 'music/assets/'.hash('sha256', 'studio').'/'.hash('sha256', $this->bytes);
    $this->disk->put($key, 'corrupt');
    expect(fn () => $this->jobs->process($command->taskId))->toThrow(InvalidAudio::class);
    Bus::dispatched(RecoverTrashTask::class)->last()->failed(new RuntimeException('/private/path secret'));
    expect($this->jobs->get($this->actor, 'studio', $command->taskId)->jsonSerialize()['error_code'])->toBe('trash_recovery_failed');
    expect(fn () => $this->jobs->cancel($this->actor, 'studio', $command->taskId))->toThrow(UploadConflict::class);
    $this->disk->put($key, $this->bytes);
    expect($this->jobs->request($this->actor, $command)->status)->toBe('queued');
    $this->jobs->process($command->taskId);
    expect($this->jobs->get($this->actor, 'studio', $command->taskId)->status)->toBe('ready');
    $this->jobs->failed((string) Str::uuid());
});

it('rolls back a recovered asset if its task acknowledgement fails and safely retries', function () {
    $command = ($this->command)();
    $this->jobs->request($this->actor, $command);
    DB::unprepared("CREATE TRIGGER ignore_recovery_ack BEFORE UPDATE OF status ON music_media_trash_recovery_tasks WHEN NEW.status = 'ready' BEGIN SELECT RAISE(IGNORE); END");
    expect(fn () => $this->jobs->process($command->taskId))->toThrow(LogicException::class);
    expect(DB::table('music_media_uploads')->where('id', $this->id)->value('status'))->toBe('trashed');
    DB::unprepared('DROP TRIGGER ignore_recovery_ack');
    $this->jobs->process($command->taskId);
    expect($this->jobs->result($this->actor, 'studio', $command->taskId)->id)->toBe($this->id);
});

it('fails closed for missing canonical users and unavailable configured providers', function () {
    $command = ($this->command)();
    $this->jobs->request($this->actor, $command);
    $this->users->shouldReceive('retrieveById')->with('7')->andReturnNull();
    expect(fn () => $this->jobs->process($command->taskId))->toThrow(AuthorizationException::class);
    app()->when(TrashRecovery::class)->needs(UserProvider::class)->give(fn () => null);
    expect(fn () => app(TrashRecovery::class)->process($command->taskId))->toThrow(AuthorizationException::class);
});

it('runs on a real synchronous queue and rolls back only its own task schema', function () {
    app()->instance(Dispatcher::class, $this->realBus);
    $command = ($this->command)();
    app(TrashRecovery::class)->request($this->actor, $command);
    expect(app(TrashRecovery::class)->get($this->actor, 'studio', $command->taskId)->status)->toBe('ready');
    $migration = require __DIR__.'/../../database/migrations/2026_11_05_233500_create_music_media_trash_recovery_tasks.php';
    $migration->down();
    expect(Schema::hasTable('music_media_trash_recovery_tasks'))->toBeFalse()
        ->and(DB::table('music_media_uploads')->where('id', $this->id)->exists())->toBeTrue();
});

it('fences delayed deliveries and failure callbacks across queued and failed recovery replays', function () {
    $command = ($this->command)();
    $this->jobs->request($this->actor, $command);
    $old = Bus::dispatched(RecoverTrashTask::class)->last();
    $this->jobs->request($this->actor, $command);
    $retry = Bus::dispatched(RecoverTrashTask::class)->last();
    expect($retry->dispatchId)->not->toBe($old->dispatchId);
    $old->handle($this->jobs);
    $old->failed(new RuntimeException('Stale queued delivery'));
    expect($this->jobs->get($this->actor, 'studio', $command->taskId)->status)->toBe('queued');
    $retry->failed(new RuntimeException('Current delivery exhausted'));
    expect($this->jobs->get($this->actor, 'studio', $command->taskId)->status)->toBe('failed');
    $this->jobs->request($this->actor, $command);
    $retry->failed(new RuntimeException('Delayed exhausted callback'));
    $retry->handle($this->jobs);
    expect($this->jobs->get($this->actor, 'studio', $command->taskId)->status)->toBe('queued');
    Bus::dispatched(RecoverTrashTask::class)->last()->handle($this->jobs);
    expect($this->jobs->result($this->actor, 'studio', $command->taskId)->id)->toBe($this->id);
});

it('ignores legacy recovery job payloads and requires a newly dispatched request', function () {
    $command = ($this->command)();
    $this->jobs->request($this->actor, $command);
    $class = RecoverTrashTask::class;
    $legacy = unserialize(sprintf('O:%d:"%s":1:{s:6:"taskId";s:36:"%s";}', strlen($class), $class, $command->taskId));
    $legacy->handle($this->jobs);
    $legacy->failed(new RuntimeException('Legacy failure'));
    expect($this->jobs->get($this->actor, 'studio', $command->taskId)->status)->toBe('queued')
        ->and(DB::table('music_media_uploads')->where('id', $this->id)->value('status'))->toBe('trashed');
    Bus::dispatched(RecoverTrashTask::class)->last()->handle($this->jobs);
    expect($this->jobs->result($this->actor, 'studio', $command->taskId)->id)->toBe($this->id);
});
