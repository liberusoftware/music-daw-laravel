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
use Liberu\Music\MediaLibrary\Commands\ProcessAsset;
use Liberu\Music\MediaLibrary\Commands\RetainAssets;
use Liberu\Music\MediaLibrary\Commands\StartUpload;
use Liberu\Music\MediaLibrary\Contracts\MediaAuthorizer;
use Liberu\Music\MediaLibrary\Events\AssetTaskChanged;
use Liberu\Music\MediaLibrary\Exceptions\InvalidAudio;
use Liberu\Music\MediaLibrary\Exceptions\UploadConflict;
use Liberu\Music\MediaLibrary\Jobs\ProcessAssetTask;
use Liberu\Music\MediaLibrary\Services\AssetProcessing;
use Liberu\Music\MediaLibrary\Services\MediaCatalog;
use Liberu\Music\MediaLibrary\Services\MediaRetention;
use Liberu\Music\MediaLibrary\Services\MediaUploads;

beforeEach(function () {
    $this->artisan('migrate', ['--path' => realpath(__DIR__.'/../../database/migrations'), '--realpath' => true])->assertSuccessful();
    config()->set('music-media.disk', 'asset-processing');
    $this->disk = Storage::fake('asset-processing');
    $this->actor = new GenericUser(['id' => 7]);
    $this->denied = null;
    foreach (['upload', 'read', 'restore', 'retain'] as $operation) {
        app(Gate::class)->define('music.media.'.$operation, fn ($actor, $workspace) => $actor->id === 7 && in_array($workspace, ['studio', 'other']) && $this->denied !== $operation);
    }
    $this->scanner = Mockery::mock(MalwareScanner::class);
    $this->scanClean = true;
    $this->scanner->shouldReceive('clean')->andReturnUsing(fn () => $this->scanClean)->byDefault();
    app()->instance(MalwareScanner::class, $this->scanner);
    $this->users = Mockery::mock(UserProvider::class);
    $this->users->shouldReceive('retrieveById')->with('7')->andReturn($this->actor)->byDefault();
    app()->when(AssetProcessing::class)->needs(UserProvider::class)->give(fn () => $this->users);
    $this->realBus = app(Dispatcher::class);
    Bus::fake();
    $format = pack('vvVVvv', 1, 1, 48000, 96000, 2, 16);
    $chunks = 'fmt '.pack('V', 16).$format.'data'.pack('V', 4).pack('v*', 0, 16384);
    $this->bytes = 'RIFF'.pack('V', strlen($chunks) + 4).'WAVE'.$chunks;
    $this->uploads = app(MediaUploads::class);
    $this->jobs = app(AssetProcessing::class);
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
    $this->command = fn (?string $id = null, ?string $asset = null, ?string $task = null) => new ProcessAsset('studio', $task ?? (string) Str::uuid(), $id ?? $this->id, $asset);
    $this->events = [];
    app(Events::class)->listen(AssetTaskChanged::class, function ($event) {
        $this->events[] = $event;
    });
});

it('queues only after commit and publishes verified bytes atomically with the task result', function () {
    $command = ($this->command)();
    DB::beginTransaction();
    $task = $this->jobs->request($this->actor, $command);
    expect($this->events)->toBe([])->and($task->status)->toBe('queued')->and($this->jobs->result($this->actor, 'studio', $task->id))->toBeNull();
    Bus::assertNothingDispatched();
    DB::commit();
    Bus::assertDispatched(ProcessAssetTask::class, fn ($job) => $job->taskId === $task->id);
    $job = unserialize(serialize(Bus::dispatched(ProcessAssetTask::class)->last()));
    $job->handle($this->jobs);
    expect($this->jobs->result($this->actor, 'studio', $task->id)->reference()['sha256'])->toBe(hash('sha256', $this->bytes))
        ->and($this->events)->toHaveCount(2)->and($job->tries)->toBe(3)->and($job->timeout)->toBe(60)->and($job->backoff)->toBe([5, 30, 120]);
    $job->handle($this->jobs);
    $this->jobs->failed($task->id);
    expect($this->jobs->request($this->actor, $command)->status)->toBe('ready')->and($this->events)->toHaveCount(2);
    expect($this->jobs->get($this->actor, 'studio', $task->id)->jsonSerialize())->toMatchArray(['schema_version' => 1, 'kind' => 'verify_upload', 'asset_id' => $this->id, 'terminal' => true, 'cancellable' => false, 'error_code' => null]);
});

it('rolls back task creation and dispatch and recovers a queue-publication outage', function () {
    $command = ($this->command)();
    DB::beginTransaction();
    $this->jobs->request($this->actor, $command);
    DB::rollBack();
    expect(DB::table('music_media_asset_tasks')->count())->toBe(0)->and($this->events)->toBe([]);
    Bus::assertNothingDispatched();
    $bus = Mockery::mock(Dispatcher::class)->shouldReceive('dispatch')->once()->andThrow(new RuntimeException('queue unavailable'))->getMock();
    $jobs = new AssetProcessing(DB::connection(), $this->uploads, app(MediaCatalog::class), $this->users, $bus, app(Events::class), app(MediaAuthorizer::class));
    expect(fn () => $jobs->request($this->actor, $command))->toThrow(RuntimeException::class);
    expect($this->jobs->get($this->actor, 'studio', $command->taskId)->status)->toBe('queued');
    $this->jobs->request($this->actor, $command);
    $this->jobs->process($command->taskId);
    expect($this->events)->toHaveCount(2);
});

it('rejects incomplete cancelled missing or wrong-workspace uploads without a task', function () {
    $incomplete = ($this->upload)(false);
    expect(fn () => $this->jobs->request($this->actor, ($this->command)($incomplete)))->toThrow(ValidationException::class);
    $this->uploads->cancel($this->actor, 'studio', $this->id);
    expect(fn () => $this->jobs->request($this->actor, ($this->command)()))->toThrow(UploadConflict::class);
    expect(fn () => $this->jobs->request($this->actor, ($this->command)((string) Str::uuid())))->toThrow(RecordNotFoundException::class);
    $foreign = ($this->upload)(true, 'other');
    expect(fn () => $this->jobs->request($this->actor, ($this->command)($foreign)))->toThrow(RecordNotFoundException::class);
    expect(DB::table('music_media_asset_tasks')->count())->toBe(0);
});

it('requires immutable command identities ownership and live permission at every stage', function () {
    $command = ($this->command)();
    $this->jobs->request($this->actor, $command);
    $other = ($this->upload)();
    expect(fn () => $this->jobs->request($this->actor, ($this->command)($other, null, $command->taskId)))->toThrow(UploadConflict::class)
        ->and(fn () => $this->jobs->get(new GenericUser(['id' => 8]), 'studio', $command->taskId))->toThrow(RecordNotFoundException::class)
        ->and(fn () => $this->jobs->cancel($this->actor, 'other', $command->taskId))->toThrow(RecordNotFoundException::class);
    $this->denied = 'upload';
    expect(fn () => $this->jobs->get($this->actor, 'studio', $command->taskId))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->jobs->process($command->taskId))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->jobs->cancel($this->actor, 'studio', $command->taskId))->toThrow(AuthorizationException::class);
});

it('cancels once without cancelling the resumable source upload or reopening the task', function () {
    $command = ($this->command)();
    $this->jobs->request($this->actor, $command);
    $cancelled = $this->jobs->cancel($this->actor, 'studio', $command->taskId);
    expect($this->jobs->cancel($this->actor, 'studio', $command->taskId))->toEqual($cancelled)
        ->and($this->jobs->request($this->actor, $command))->toEqual($cancelled);
    $this->jobs->process($command->taskId);
    $this->jobs->failed($command->taskId);
    expect($this->jobs->result($this->actor, 'studio', $command->taskId))->toBeNull()
        ->and($this->uploads->status($this->actor, 'studio', $this->id)->status)->toBe('uploading')->and($this->events)->toHaveCount(2);
});

it('retries scanner failures without exposing raw errors and refuses cancellation after completion', function () {
    $command = ($this->command)();
    $this->jobs->request($this->actor, $command);
    $this->scanClean = false;
    expect(fn () => $this->jobs->process($command->taskId))->toThrow(InvalidAudio::class);
    Bus::dispatched(ProcessAssetTask::class)->last()->failed(new RuntimeException('/private/path'));
    $failed = $this->jobs->get($this->actor, 'studio', $command->taskId);
    expect($failed->status)->toBe('failed')->and(json_encode($failed))->not->toContain('private')->and($failed->jsonSerialize()['error_code'])->toBe('asset_processing_failed');
    $this->jobs->process($command->taskId);
    expect(fn () => $this->jobs->cancel($this->actor, 'studio', $command->taskId))->toThrow(UploadConflict::class);
    $this->scanClean = true;
    $this->jobs->request($this->actor, $command);
    $this->jobs->process($command->taskId);
    expect(fn () => $this->jobs->cancel($this->actor, 'studio', $command->taskId))->toThrow(UploadConflict::class);
    $this->jobs->failed((string) Str::uuid());
});

it('restores missing referenced media without changing its identity or retention', function () {
    $target = $this->uploads->complete($this->actor, 'studio', $this->id);
    app(MediaRetention::class)->retain($this->actor, new RetainAssets('studio', 'revision:1', [$target->id]));
    $key = DB::table('music_media_uploads')->where('id', $target->id)->value('blob_key');
    $this->disk->delete($key);
    $replacement = ($this->upload)();
    $command = ($this->command)($replacement, $target->id);
    $task = $this->jobs->request($this->actor, $command);
    expect($task->kind())->toBe('restore_asset')->and($task->jsonSerialize()['progress']['unit'])->toBe('asset_restoration');
    $this->jobs->process($task->id);
    expect($this->jobs->result($this->actor, 'studio', $task->id)->id)->toBe($target->id)->and($this->disk->exists($key))->toBeTrue()
        ->and(DB::table('music_media_retention')->where('asset_id', $target->id)->count())->toBe(1);
});

it('refuses lost restoration permission and mismatching replacement content', function () {
    $target = $this->uploads->complete($this->actor, 'studio', $this->id);
    $other = ($this->upload)(true, 'studio', substr_replace($this->bytes, pack('v', 1), -2));
    $command = ($this->command)($other, $target->id);
    $this->jobs->request($this->actor, $command);
    $this->denied = 'restore';
    expect(fn () => $this->jobs->process($command->taskId))->toThrow(AuthorizationException::class);
    $this->denied = null;
    expect(fn () => $this->jobs->process($command->taskId))->toThrow(UploadConflict::class);
});

it('rolls publication back when task acknowledgement fails and safely retries', function () {
    $command = ($this->command)();
    $this->jobs->request($this->actor, $command);
    DB::unprepared("CREATE TRIGGER refuse_ready BEFORE UPDATE OF status ON music_media_asset_tasks WHEN NEW.status = 'ready' BEGIN SELECT RAISE(IGNORE); END");
    expect(fn () => $this->jobs->process($command->taskId))->toThrow(LogicException::class);
    expect($this->uploads->status($this->actor, 'studio', $this->id)->status)->toBe('uploading')->and($this->events)->toHaveCount(1);
    DB::unprepared('DROP TRIGGER refuse_ready');
    $this->jobs->process($command->taskId);
    expect($this->jobs->get($this->actor, 'studio', $command->taskId)->status)->toBe('ready');
});

it('resolves canonical users and fails closed when they are removed or unavailable', function () {
    $command = ($this->command)();
    $this->jobs->request($this->actor, $command);
    $this->users->shouldReceive('retrieveById')->with('7')->andReturnNull();
    expect(fn () => $this->jobs->process($command->taskId))->toThrow(AuthorizationException::class);
    $jobs = new AssetProcessing(DB::connection(), $this->uploads, app(MediaCatalog::class), null, app(Dispatcher::class), app(Events::class), app(MediaAuthorizer::class));
    expect(fn () => $jobs->process($command->taskId))->toThrow(AuthorizationException::class);
    expect(fn () => $jobs->process((string) Str::uuid()))->toThrow(RecordNotFoundException::class);
});

it('executes on the real sync queue and keeps schema rollback non-destructive', function () {
    config()->set('queue.default', 'sync');
    Bus::swap($this->realBus);
    $jobs = app(AssetProcessing::class);
    $command = ($this->command)();
    $jobs->request($this->actor, $command);
    expect($jobs->get($this->actor, 'studio', $command->taskId)->status)->toBe('ready');
    $migration = require glob(__DIR__.'/../../database/migrations/*create_music_media_asset_tasks.php')[0];
    $migration->down();
    expect($this->uploads->get($this->actor, 'studio', $this->id)->id)->toBe($this->id);
    $migration->up();
});

it('validates task commands and prevents using the restoration target as its source', function () {
    expect(fn () => new ProcessAsset('', 'bad', 'bad'))->toThrow(ValidationException::class)
        ->and(fn () => ($this->command)($this->id, $this->id))->toThrow(ValidationException::class);
});

it('fences both delayed deliveries and failure callbacks across queued and failed replays', function () {
    $command = ($this->command)();
    $this->jobs->request($this->actor, $command);
    $old = Bus::dispatched(ProcessAssetTask::class)->last();
    $this->jobs->request($this->actor, $command);
    $retry = Bus::dispatched(ProcessAssetTask::class)->last();
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
    Bus::dispatched(ProcessAssetTask::class)->last()->handle($this->jobs);
    expect($this->jobs->result($this->actor, 'studio', $command->taskId)->id)->toBe($this->id);
});

it('ignores pre-upgrade serialized jobs without granting them the current dispatch identity', function () {
    $command = ($this->command)();
    $this->jobs->request($this->actor, $command);
    $class = ProcessAssetTask::class;
    $legacy = unserialize(sprintf('O:%d:"%s":1:{s:6:"taskId";s:36:"%s";}', strlen($class), $class, $command->taskId));
    $legacy->handle($this->jobs);
    $legacy->failed(new RuntimeException('Legacy failure'));
    expect($this->jobs->get($this->actor, 'studio', $command->taskId)->status)->toBe('queued')
        ->and($this->uploads->status($this->actor, 'studio', $this->id)->status)->toBe('uploading');
    Bus::dispatched(ProcessAssetTask::class)->last()->handle($this->jobs);
    expect($this->jobs->result($this->actor, 'studio', $command->taskId)->id)->toBe($this->id);
});

it('preserves legacy tasks and recordings across dispatch schema rollback and reenables them through request replay', function () {
    $command = ($this->command)();
    $this->jobs->request($this->actor, $command);
    $old = Bus::dispatched(ProcessAssetTask::class)->last();
    $migration = require __DIR__.'/../../database/migrations/2026_11_13_213405_add_dispatch_identity_to_music_media_asset_tasks.php';
    $migration->down();
    expect(Schema::hasColumn('music_media_asset_tasks', 'dispatch_id'))->toBeFalse()
        ->and(Schema::hasColumn('music_media_trash_recovery_tasks', 'dispatch_id'))->toBeFalse()
        ->and(DB::table('music_media_asset_tasks')->where('id', $command->taskId)->value('status'))->toBe('queued')
        ->and($this->uploads->status($this->actor, 'studio', $this->id)->receivedBytes)->toBe(strlen($this->bytes));
    $migration->up();
    expect(DB::table('music_media_asset_tasks')->where('id', $command->taskId)->value('dispatch_id'))->toBeNull();
    $old->handle($this->jobs);
    $old->failed(new RuntimeException('Stale generation after migration'));
    expect($this->jobs->get($this->actor, 'studio', $command->taskId)->status)->toBe('queued');
    $this->jobs->request($this->actor, $command);
    Bus::dispatched(ProcessAssetTask::class)->last()->handle($this->jobs);
    expect($this->jobs->result($this->actor, 'studio', $command->taskId)->id)->toBe($this->id);
});
