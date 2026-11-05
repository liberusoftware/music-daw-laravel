<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Events\Dispatcher as Events;
use Illuminate\Database\RecordNotFoundException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Liberu\Foundation\Files\Contracts\MalwareScanner;
use Liberu\Music\MediaLibrary\Commands\RequestWaveform;
use Liberu\Music\MediaLibrary\Commands\StartUpload;
use Liberu\Music\MediaLibrary\Contracts\MediaAuthorizer;
use Liberu\Music\MediaLibrary\Events\WaveformTaskChanged;
use Liberu\Music\MediaLibrary\Exceptions\InvalidAudio;
use Liberu\Music\MediaLibrary\Exceptions\UploadConflict;
use Liberu\Music\MediaLibrary\Jobs\BuildWaveform;
use Liberu\Music\MediaLibrary\MusicMediaLibraryServiceProvider;
use Liberu\Music\MediaLibrary\Services\AssetWaveforms;
use Liberu\Music\MediaLibrary\Services\CachedWaveforms;
use Liberu\Music\MediaLibrary\Services\MediaUploads;
use Liberu\Music\MediaLibrary\Services\WaveformProcessing;

beforeEach(function () {
    $this->artisan('migrate', ['--path' => realpath(__DIR__.'/../../database/migrations'), '--realpath' => true])->assertSuccessful();
    config()->set('music-media.disk', 'waveform-processing');
    $this->disk = Storage::fake('waveform-processing');
    app(Repository::class)->flush();
    $this->actor = new GenericUser(['id' => 7]);
    $this->allowed = true;
    foreach (['upload', 'read'] as $operation) {
        app(Gate::class)->define('music.media.'.$operation, fn ($actor, $workspace) => $this->allowed && $actor->id === 7 && in_array($workspace, ['studio', 'other']));
    }
    app()->instance(MalwareScanner::class, Mockery::mock(MalwareScanner::class)->shouldReceive('clean')->andReturnTrue()->getMock());
    $this->users = Mockery::mock(UserProvider::class);
    $this->users->shouldReceive('retrieveById')->with('7')->andReturn($this->actor)->byDefault();
    app()->when(WaveformProcessing::class)->needs(UserProvider::class)->give(fn () => $this->users);
    $this->realBus = app(Dispatcher::class);
    Bus::fake();
    $this->uploads = app(MediaUploads::class);
    $this->cache = app(CachedWaveforms::class);
    $this->processing = app(WaveformProcessing::class);
    $this->id = (string) Str::uuid();
    $format = pack('vvVVvv', 1, 1, 48000, 96000, 2, 16);
    $chunks = 'fmt '.pack('V', 16).$format.'data'.pack('V', 4).pack('v*', 0, 16384);
    $this->bytes = 'RIFF'.pack('V', strlen($chunks) + 4).'WAVE'.$chunks;
    $this->publish = function (string $workspace = 'studio', ?string $id = null): string {
        $id ??= $this->id;
        $this->uploads->start($this->actor, new StartUpload($workspace, $id, 'Recording.wav', strlen($this->bytes), hash('sha256', $this->bytes), []));
        $this->uploads->append($this->actor, $workspace, $id, 0, $this->bytes);
        $this->uploads->complete($this->actor, $workspace, $id);

        return $id;
    };
    ($this->publish)();
    $this->command = fn (?string $taskId = null, int $window = 1) => new RequestWaveform('studio', $taskId ?? (string) Str::uuid(), $this->id, $window);
    $this->events = [];
    app(Events::class)->listen(WaveformTaskChanged::class, function ($event) {
        $this->events[] = $event;
    });
});

it('queues only after commit and returns authorized versioned task and cached result', function () {
    $command = ($this->command)();
    DB::beginTransaction();
    $task = $this->processing->request($this->actor, $command);
    Bus::assertNothingDispatched();
    expect($task->status)->toBe('queued')->and($this->processing->result($this->actor, 'studio', $task->id))->toBeNull();
    DB::commit();
    Bus::assertDispatched(BuildWaveform::class, fn ($job) => $job->taskId === $task->id);
    $job = unserialize(serialize(new BuildWaveform($task->id)));
    $job->handle($this->processing);
    expect($this->processing->get($this->actor, 'studio', $task->id)->status)->toBe('ready')
        ->and($this->processing->result($this->actor, 'studio', $task->id)->peaks)->toHaveCount(2)
        ->and($this->events)->toHaveCount(2)->and($job->tries)->toBe(3)->and($job->timeout)->toBe(60)->and($job->backoff)->toBe([5, 30, 120]);
    $job->handle($this->processing);
    $this->processing->request($this->actor, $command);
    Bus::assertDispatchedTimes(BuildWaveform::class, 1);
    expect($this->events)->toHaveCount(2)->and(json_decode(json_encode($this->events[1]->task), true))->toBe([
        'schema_version' => 1, 'id' => $task->id, 'workspace_id' => 'studio', 'asset_id' => $this->id, 'requested_sample_frames_per_peak' => 1, 'status' => 'ready', 'terminal' => true, 'cancellable' => false,
        'progress' => ['completed_steps' => 1, 'total_steps' => 1, 'unit' => 'waveform_build'],
    ]);
});

it('rolls back task creation and dispatch together', function () {
    $command = ($this->command)();
    DB::beginTransaction();
    $this->processing->request($this->actor, $command);
    DB::rollBack();
    Bus::assertNothingDispatched();
    expect(DB::table('music_media_waveform_tasks')->count())->toBe(0);
});

it('replays queued and failed requests and recovers evicted previews without new identities', function () {
    $command = ($this->command)();
    $this->processing->request($this->actor, $command);
    $this->processing->request($this->actor, $command);
    (new BuildWaveform($command->taskId))->failed(new RuntimeException('/private/audio'));
    expect($this->processing->get($this->actor, 'studio', $command->taskId)->status)->toBe('failed')
        ->and(json_encode($this->events[0]))->not->toContain('private');
    $this->processing->failed($command->taskId);
    $this->processing->failed((string) Str::uuid());
    expect($this->events)->toHaveCount(2);
    $this->processing->request($this->actor, $command);
    $this->processing->process($command->taskId);
    $this->processing->failed($command->taskId);
    expect($this->processing->get($this->actor, 'studio', $command->taskId)->status)->toBe('ready');
    app(Repository::class)->flush();
    expect($this->processing->result($this->actor, 'studio', $command->taskId))->toBeNull();
    $this->processing->request($this->actor, $command);
    expect($this->processing->get($this->actor, 'studio', $command->taskId)->status)->toBe('queued');
    $this->processing->process($command->taskId);
    expect($this->processing->result($this->actor, 'studio', $command->taskId))->not->toBeNull()->and(DB::table('music_media_waveform_tasks')->count())->toBe(1);
    Bus::assertDispatchedTimes(BuildWaveform::class, 4);
});

it('isolates task owners and rejects changed declarations and unavailable assets', function () {
    $command = ($this->command)();
    $this->processing->request($this->actor, $command);
    expect(fn () => $this->processing->request($this->actor, ($this->command)($command->taskId, 2)))->toThrow(UploadConflict::class);
    foreach ([new GenericUser(['id' => 8])] as $actor) {
        expect(fn () => $this->processing->get($actor, 'studio', $command->taskId))->toThrow(RecordNotFoundException::class);
    }
    expect(fn () => $this->processing->get($this->actor, 'other', $command->taskId))->toThrow(RecordNotFoundException::class)
        ->and(fn () => $this->processing->process((string) Str::uuid()))->toThrow(RecordNotFoundException::class);
    $this->allowed = false;
    expect(fn () => $this->processing->request($this->actor, $command))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->processing->result($this->actor, 'studio', $command->taskId))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->processing->process($command->taskId))->toThrow(AuthorizationException::class);
});

it('fails closed for removed users or an unavailable canonical provider', function () {
    $command = ($this->command)();
    $this->processing->request($this->actor, $command);
    $this->users->shouldReceive('retrieveById')->with('7')->andReturnNull();
    expect(fn () => $this->processing->process($command->taskId))->toThrow(AuthorizationException::class);
    $processing = new WaveformProcessing(DB::connection(), $this->uploads, $this->cache, null, app(Dispatcher::class), app(Events::class), app(MediaAuthorizer::class));
    expect(fn () => $processing->process($command->taskId))->toThrow(AuthorizationException::class);
});

it('refuses task source drift and never caches failed audio processing', function () {
    $command = ($this->command)();
    $this->processing->request($this->actor, $command);
    DB::table('music_media_waveform_tasks')->where('id', $command->taskId)->update(['source_sha256' => str_repeat('f', 64)]);
    expect(fn () => $this->processing->process($command->taskId))->toThrow(UploadConflict::class);
    app(Repository::class)->flush();
    $key = DB::table('music_media_uploads')->where('id', $this->id)->value('blob_key');
    $this->disk->put($key, 'broken');
    expect(fn () => $this->cache->build($this->actor, 'studio', $this->id, 1))->toThrow(InvalidAudio::class)
        ->and($this->cache->get($this->actor, 'studio', $this->id, 1))->toBeNull();
});

it('keys cached previews by workspace checksum and window while checking every read', function () {
    $waveform = $this->cache->build($this->actor, 'studio', $this->id, 1);
    $alias = ($this->publish)('studio', (string) Str::uuid());
    expect($this->cache->get($this->actor, 'studio', $alias, 1))->toEqual($waveform)
        ->and($this->cache->get($this->actor, 'studio', $this->id, 2))->toBeNull();
    $other = ($this->publish)('other', (string) Str::uuid());
    expect($this->cache->get($this->actor, 'other', $other, 1))->toBeNull();
    $this->allowed = false;
    expect(fn () => $this->cache->get($this->actor, 'studio', $this->id, 1))->toThrow(AuthorizationException::class);
    $this->allowed = true;
    $this->disk->delete(DB::table('music_media_uploads')->where('id', $this->id)->value('blob_key'));
    expect(fn () => $this->cache->get($this->actor, 'studio', $this->id, 1))->toThrow(InvalidAudio::class);
});

it('validates processing commands and direct cache windows', function () {
    foreach ([0, 1048577] as $window) {
        expect(fn () => ($this->command)(null, $window))->toThrow(ValidationException::class)
            ->and(fn () => $this->cache->build($this->actor, 'studio', $this->id, $window))->toThrow(InvalidArgumentException::class);
    }
    expect(fn () => new RequestWaveform('', 'invalid', 'invalid'))->toThrow(ValidationException::class);
});

it('preserves durable queued work across publication failure and permits retry', function () {
    $command = ($this->command)();
    $bus = Mockery::mock(Dispatcher::class);
    $bus->shouldReceive('dispatch')->once()->andThrow(new RuntimeException('queue unavailable'));
    $processing = new WaveformProcessing(DB::connection(), $this->uploads, $this->cache, $this->users, $bus, app(Events::class), app(MediaAuthorizer::class));
    expect(fn () => $processing->request($this->actor, $command))->toThrow(RuntimeException::class, 'queue unavailable');
    expect($this->processing->get($this->actor, 'studio', $command->taskId)->status)->toBe('queued');
    $this->processing->request($this->actor, $command);
    $this->processing->process($command->taskId);
    expect($this->processing->get($this->actor, 'studio', $command->taskId)->status)->toBe('ready');
});

it('defers completion events and permits retry after the completion transaction rolls back', function () {
    $command = ($this->command)();
    $this->processing->request($this->actor, $command);
    $this->events = [];
    DB::beginTransaction();
    $this->processing->process($command->taskId);
    expect($this->events)->toBe([]);
    DB::rollBack();
    expect($this->processing->get($this->actor, 'studio', $command->taskId)->status)->toBe('queued');
    $this->processing->process($command->taskId);
    expect($this->events)->toHaveCount(1);
});

it('keeps cache and status queries bounded without reading source audio', function () {
    $command = ($this->command)();
    $this->processing->request($this->actor, $command);
    $this->processing->process($command->taskId);
    DB::enableQueryLog();
    DB::flushQueryLog();
    expect($this->processing->result($this->actor, 'studio', $command->taskId))->not->toBeNull();
    expect(DB::getQueryLog())->toHaveCount(2);
    DB::disableQueryLog();
    $migration = require glob(__DIR__.'/../../database/migrations/*create_music_media_waveform_tasks.php')[0];
    $migration->down();
    expect(DB::table('music_media_uploads')->where('id', $this->id)->exists())->toBeTrue();
    $migration->up();
});

it('executes through the real sync queue after commit with the canonical requester', function () {
    config()->set('queue.default', 'sync');
    Bus::swap($this->realBus);
    $processing = app(WaveformProcessing::class);
    $command = ($this->command)();
    $processing->request($this->actor, $command);
    expect($processing->get($this->actor, 'studio', $command->taskId)->status)->toBe('ready')
        ->and($processing->result($this->actor, 'studio', $command->taskId)->audio->sha256)->toBe(hash('sha256', $this->bytes));
});

it('cancels owned tasks once and prevents replay or late delivery from reopening them', function () {
    $command = ($this->command)();
    $this->processing->request($this->actor, $command);
    $task = $this->processing->cancel($this->actor, 'studio', $command->taskId);
    expect($task->status)->toBe('cancelled')->and($task->jsonSerialize()['terminal'])->toBeTrue()
        ->and($task->jsonSerialize()['cancellable'])->toBeFalse()->and($task->jsonSerialize()['progress']['completed_steps'])->toBe(0);
    expect($this->processing->cancel($this->actor, 'studio', $command->taskId))->toEqual($task);
    expect($this->processing->request($this->actor, $command))->toEqual($task);
    $this->processing->process($command->taskId);
    $this->processing->failed($command->taskId);
    expect($this->processing->result($this->actor, 'studio', $command->taskId))->toBeNull()->and($this->events)->toHaveCount(2);
    Bus::assertDispatchedTimes(BuildWaveform::class, 1);
});

it('checks task ownership and live read permission when cancelling', function () {
    $command = ($this->command)();
    $this->processing->request($this->actor, $command);
    expect(fn () => $this->processing->cancel(new GenericUser(['id' => 8]), 'studio', $command->taskId))->toThrow(RecordNotFoundException::class)
        ->and(fn () => $this->processing->cancel($this->actor, 'other', $command->taskId))->toThrow(RecordNotFoundException::class);
    $this->allowed = false;
    expect(fn () => $this->processing->cancel($this->actor, 'studio', $command->taskId))->toThrow(AuthorizationException::class);
});

it('keeps cancellation transactional and rejects cancellation after completion or terminal failure', function () {
    $command = ($this->command)();
    $this->processing->request($this->actor, $command);
    DB::beginTransaction();
    $this->processing->cancel($this->actor, 'studio', $command->taskId);
    expect($this->events)->toHaveCount(1);
    DB::rollBack();
    expect($this->processing->get($this->actor, 'studio', $command->taskId)->status)->toBe('queued');
    $this->processing->process($command->taskId);
    expect(fn () => $this->processing->cancel($this->actor, 'studio', $command->taskId))->toThrow(UploadConflict::class);
    $failed = ($this->command)();
    $this->processing->request($this->actor, $failed);
    $this->processing->failed($failed->taskId);
    expect(fn () => $this->processing->cancel($this->actor, 'studio', $failed->taskId))->toThrow(UploadConflict::class);
});

it('permits failure status and cancellation metadata when source audio is missing', function () {
    $command = ($this->command)();
    $this->processing->request($this->actor, $command);
    $this->disk->delete(DB::table('music_media_uploads')->where('id', $this->id)->value('blob_key'));
    expect($this->processing->get($this->actor, 'studio', $command->taskId)->status)->toBe('queued');
    expect($this->processing->cancel($this->actor, 'studio', $command->taskId)->status)->toBe('cancelled');
});

it('refuses an ignored cancellation transition without emitting a false event', function () {
    $command = ($this->command)();
    $this->processing->request($this->actor, $command);
    DB::unprepared("CREATE TRIGGER ignore_cancel BEFORE UPDATE OF status ON music_media_waveform_tasks WHEN NEW.status = 'cancelled' BEGIN SELECT RAISE(IGNORE); END");
    expect(fn () => $this->processing->cancel($this->actor, 'studio', $command->taskId))->toThrow(UploadConflict::class);
    expect($this->events)->toHaveCount(1)->and($this->processing->get($this->actor, 'studio', $command->taskId)->status)->toBe('queued');
});

it('does not publish a ready result when cancellation commits during derivation', function () {
    $command = ($this->command)();
    $this->processing->request($this->actor, $command);
    $store = Mockery::mock(Repository::class);
    $store->shouldReceive('remember')->once()->andReturnUsing(function ($key, $ttl, $build) use ($command) {
        $this->processing->cancel($this->actor, 'studio', $command->taskId);

        return $build();
    });
    $cached = new CachedWaveforms($this->uploads, app(AssetWaveforms::class), $store);
    $processing = new WaveformProcessing(DB::connection(), $this->uploads, $cached, $this->users, app(Dispatcher::class), app(Events::class), app(MediaAuthorizer::class));
    $processing->process($command->taskId);
    expect($this->processing->get($this->actor, 'studio', $command->taskId)->status)->toBe('cancelled')
        ->and($this->processing->result($this->actor, 'studio', $command->taskId))->toBeNull()->and($this->events)->toHaveCount(2);
});

it('resolves the canonical provider independently of later Sanctum guard selection', function (string $guard, ?string $explicit) {
    $command = ($this->command)();
    $this->processing->request($this->actor, $command);
    app('auth')->provider('music-test', fn () => $this->users);
    config()->set('auth.providers.canonical', ['driver' => 'music-test']);
    config()->set('auth.guards.web.provider', 'canonical');
    config()->set('auth.defaults.passwords', 'users');
    config()->set('auth.passwords.users.provider', 'canonical');
    config()->set('auth.defaults.guard', $guard);
    config()->set('music-media.user_provider', $explicit);
    (new MusicMediaLibraryServiceProvider(app()))->register();
    config()->set('auth.defaults.guard', 'sanctum');
    app(WaveformProcessing::class)->process($command->taskId);
    expect($this->processing->get($this->actor, 'studio', $command->taskId)->status)->toBe('ready');
})->with([['web', null], ['sanctum', null], ['sanctum', 'canonical']]);
