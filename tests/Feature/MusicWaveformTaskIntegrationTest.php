<?php

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\RecordNotFoundException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Liberu\Foundation\Files\Contracts\MalwareScanner;
use Liberu\Music\MediaLibrary\Commands\RequestWaveform;
use Liberu\Music\MediaLibrary\Commands\StartUpload;
use Liberu\Music\MediaLibrary\Exceptions\UploadConflict;
use Liberu\Music\MediaLibrary\Jobs\BuildWaveform;
use Liberu\Music\MediaLibrary\MusicMediaLibraryServiceProvider;
use Liberu\Music\MediaLibrary\Services\MediaUploads;
use Liberu\Music\MediaLibrary\Services\WaveformProcessing;

beforeEach(function () {
    $this->app->register(MusicMediaLibraryServiceProvider::class);
    $this->artisan('migrate', ['--path' => base_path('modules/music-media-library/database/migrations'), '--realpath' => true])->assertSuccessful();
    config()->set('music-media.disk', 'host-waveform-task');
    $this->disk = Storage::fake('host-waveform-task');
    $this->actor = User::factory()->create();
    foreach (['upload', 'read'] as $operation) {
        Gate::define('music.media.'.$operation, fn ($actor, $workspace) => $actor->is($this->actor) && $workspace === 'studio');
    }
    $scanner = Mockery::mock(MalwareScanner::class);
    $scanner->shouldReceive('clean')->once()->andReturnTrue();
    app()->instance(MalwareScanner::class, $scanner);
    $format = pack('vvVVvv', 1, 1, 48000, 96000, 2, 16);
    $chunks = 'fmt '.pack('V', 16).$format.'data'.pack('V', 4).pack('v*', 0, 16384);
    $bytes = 'RIFF'.pack('V', strlen($chunks) + 4).'WAVE'.$chunks;
    $uploads = app(MediaUploads::class);
    $this->assetId = (string) Str::uuid();
    $uploads->start($this->actor, new StartUpload('studio', $this->assetId, 'Recording.wav', strlen($bytes), hash('sha256', $bytes)));
    $uploads->append($this->actor, 'studio', $this->assetId, 0, $bytes);
    $uploads->complete($this->actor, 'studio', $this->assetId);
    Bus::fake();
    $this->processing = app(WaveformProcessing::class);
    $this->command = new RequestWaveform('studio', (string) Str::uuid(), $this->assetId, 1);
    $this->jobFor = fn (string $id) => new BuildWaveform($id, DB::table('music_media_waveform_tasks')->where('id', $id)->value('dispatch_id'));
});

it('resolves the real host requester in serialized jobs and returns explicit progress and frame units', function () {
    $task = $this->processing->request($this->actor, $this->command);
    expect($task->jsonSerialize())->toMatchArray(['status' => 'queued', 'terminal' => false, 'cancellable' => true,
        'progress' => ['completed_steps' => 0, 'total_steps' => 1, 'unit' => 'waveform_build']])
        ->and($this->processing->result($this->actor, 'studio', $task->id))->toBeNull();
    $job = unserialize(serialize(($this->jobFor)($task->id)));
    $job->handle($this->processing);
    $ready = $this->processing->get($this->actor, 'studio', $task->id);
    expect($ready->jsonSerialize())->toMatchArray(['status' => 'ready', 'terminal' => true, 'cancellable' => false,
        'progress' => ['completed_steps' => 1, 'total_steps' => 1, 'unit' => 'waveform_build']]);
    $result = $this->processing->result($this->actor, 'studio', $task->id)->jsonSerialize();
    expect($result)->toMatchArray(['schema_version' => 1, 'sample_rate' => 48000, 'channels' => 1, 'sample_frames' => 2, 'sample_frames_per_peak' => 1])
        ->and($result['peaks'][0]['start_sample_frame'])->toBe(0)->and($result['peaks'][1]['end_sample_frame'])->toBe(2);
    expect($this->processing->request($this->actor, $this->command)->status)->toBe('ready');
    $job->failed(new RuntimeException('Late duplicate failure'));
    expect($this->processing->get($this->actor, 'studio', $task->id)->status)->toBe('ready');
    expect(fn () => $this->processing->cancel($this->actor, 'studio', $task->id))->toThrow(UploadConflict::class);
});

it('retries exhausted jobs and rebuilds evicted caches without changing task identity', function () {
    $task = $this->processing->request($this->actor, $this->command);
    $job = ($this->jobFor)($task->id);
    $job->failed(new RuntimeException('Worker unavailable'));
    expect($this->processing->get($this->actor, 'studio', $task->id)->status)->toBe('failed');
    expect(fn () => $this->processing->cancel($this->actor, 'studio', $task->id))->toThrow(UploadConflict::class);
    expect($this->processing->request($this->actor, $this->command)->status)->toBe('queued');
    $job = ($this->jobFor)($task->id);
    $job->handle($this->processing);
    app(Repository::class)->flush();
    expect($this->processing->result($this->actor, 'studio', $task->id))->toBeNull()
        ->and($this->processing->request($this->actor, $this->command)->id)->toBe($task->id);
    $job = ($this->jobFor)($task->id);
    $job->handle($this->processing);
    expect($this->processing->result($this->actor, 'studio', $task->id))->not->toBeNull()
        ->and(DB::table('music_media_waveform_tasks')->count())->toBe(1);
});

it('keeps cancellation terminal across request replay and late job delivery', function () {
    $task = $this->processing->request($this->actor, $this->command);
    $cancelled = $this->processing->cancel($this->actor, 'studio', $task->id);
    expect($cancelled->jsonSerialize())->toMatchArray(['status' => 'cancelled', 'terminal' => true, 'cancellable' => false]);
    expect($this->processing->cancel($this->actor, 'studio', $task->id))->toEqual($cancelled)
        ->and($this->processing->request($this->actor, $this->command))->toEqual($cancelled);
    $job = ($this->jobFor)($task->id);
    $job->handle($this->processing);
    $job->failed(new RuntimeException('Late failure'));
    expect($this->processing->get($this->actor, 'studio', $task->id))->toEqual($cancelled)
        ->and($this->processing->result($this->actor, 'studio', $task->id))->toBeNull();
});

it('isolates task ownership and workspace and enforces revoked permissions in workers', function () {
    $task = $this->processing->request($this->actor, $this->command);
    $outsider = User::factory()->create();
    expect(fn () => $this->processing->get($outsider, 'studio', $task->id))->toThrow(RecordNotFoundException::class)
        ->and(fn () => $this->processing->cancel($this->actor, 'other', $task->id))->toThrow(RecordNotFoundException::class);
    Gate::define('music.media.read', fn () => false);
    expect(fn () => $this->processing->get($this->actor, 'studio', $task->id))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->processing->cancel($this->actor, 'studio', $task->id))->toThrow(AuthorizationException::class)
        ->and(fn () => ($this->jobFor)($task->id)->handle($this->processing))->toThrow(AuthorizationException::class);
});

it('refuses to impersonate a deleted requester and keeps missing-source status cancellable', function () {
    $task = $this->processing->request($this->actor, $this->command);
    $this->disk->delete(DB::table('music_media_uploads')->where('id', $this->assetId)->value('blob_key'));
    expect($this->processing->get($this->actor, 'studio', $task->id)->status)->toBe('queued');
    $this->actor->delete();
    expect(fn () => ($this->jobFor)($task->id)->handle($this->processing))->toThrow(AuthorizationException::class, 'requester is unavailable');
    expect($this->processing->cancel($this->actor, 'studio', $task->id)->status)->toBe('cancelled');
});

it('rejects changed task parameters and preserves a queued task when cancellation rolls back', function () {
    $task = $this->processing->request($this->actor, $this->command);
    expect(fn () => $this->processing->request($this->actor, new RequestWaveform('studio', $task->id, $this->assetId, 2)))->toThrow(UploadConflict::class);
    DB::beginTransaction();
    $this->processing->cancel($this->actor, 'studio', $task->id);
    DB::rollBack();
    expect($this->processing->get($this->actor, 'studio', $task->id)->status)->toBe('queued');
});

it('refuses a corrupted task source fingerprint without publishing a ready result', function () {
    $task = $this->processing->request($this->actor, $this->command);
    DB::table('music_media_waveform_tasks')->where('id', $task->id)->update(['source_sha256' => str_repeat('0', 64)]);
    expect(fn () => ($this->jobFor)($task->id)->handle($this->processing))->toThrow(UploadConflict::class, 'source changed');
    expect($this->processing->get($this->actor, 'studio', $task->id)->status)->toBe('queued')
        ->and($this->processing->result($this->actor, 'studio', $task->id))->toBeNull();
});

it('rejects a suppressed cancellation update instead of reporting a false terminal result', function () {
    $task = $this->processing->request($this->actor, $this->command);
    DB::unprepared("CREATE TRIGGER ignore_host_cancel BEFORE UPDATE OF status ON music_media_waveform_tasks WHEN NEW.status = 'cancelled' BEGIN SELECT RAISE(IGNORE); END");
    try {
        expect(fn () => $this->processing->cancel($this->actor, 'studio', $task->id))->toThrow(UploadConflict::class, 'changed during cancellation');
        expect($this->processing->get($this->actor, 'studio', $task->id)->status)->toBe('queued');
    } finally {
        DB::unprepared('DROP TRIGGER ignore_host_cancel');
    }
});
