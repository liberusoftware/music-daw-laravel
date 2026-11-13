<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Events\Dispatcher as Events;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\RecordNotFoundException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Liberu\Foundation\Files\Contracts\MalwareScanner;
use Liberu\Foundation\ImportExport\Contracts\TransferAuthorizer;
use Liberu\Music\MediaLibrary\Commands\RequestArchiveExport;
use Liberu\Music\MediaLibrary\Commands\SaveMediaPack;
use Liberu\Music\MediaLibrary\Commands\StartUpload;
use Liberu\Music\MediaLibrary\Events\ArchiveExportChanged;
use Liberu\Music\MediaLibrary\Exceptions\ArchiveResultExpired;
use Liberu\Music\MediaLibrary\Exceptions\InvalidAudio;
use Liberu\Music\MediaLibrary\Exceptions\UploadConflict;
use Liberu\Music\MediaLibrary\Jobs\ExportPackArchiveTask;
use Liberu\Music\MediaLibrary\Services\ArchiveExports;
use Liberu\Music\MediaLibrary\Services\MediaPacks;
use Liberu\Music\MediaLibrary\Services\MediaUploads;

beforeEach(function () {
    $this->artisan('migrate', ['--path' => realpath(__DIR__.'/../../database/migrations'), '--realpath' => true])->assertSuccessful();
    config()->set('music-media.disk', 'queued-pack-exports');
    $this->disk = Storage::fake('queued-pack-exports');
    $this->actor = new GenericUser(['id' => 1]);
    $this->denied = null;
    foreach (['upload', 'read', 'retain', 'pack_write', 'pack_read', 'pack_export', 'distribute', 'archive_cleanup'] as $operation) {
        app(Gate::class)->define('music.media.'.$operation, fn ($actor, $workspace) => $actor->id === 1 && in_array($workspace, ['studio', 'other']) && $this->denied !== $operation);
    }
    app(Gate::class)->define('music.media.pack_distribute', fn () => $this->denied !== 'pack_distribute');
    app()->instance(MalwareScanner::class, Mockery::mock(MalwareScanner::class)->shouldReceive('clean')->andReturnTrue()->getMock());
    $this->allowed = true;
    app()->instance(TransferAuthorizer::class, Mockery::mock(TransferAuthorizer::class)->shouldReceive('allowed')->with('1', 'music-media-pack', 'export')->andReturnUsing(fn () => $this->allowed)->getMock());
    $this->users = Mockery::mock(UserProvider::class)->shouldReceive('retrieveById')->with('1')->andReturn($this->actor)->byDefault()->getMock();
    app()->when(ArchiveExports::class)->needs(UserProvider::class)->give(fn () => $this->users);
    $this->realBus = app(Dispatcher::class);
    Bus::fake();
    $format = pack('vvVVvv', 1, 1, 48000, 96000, 2, 16);
    $data = 'fmt '.pack('V', 16).$format.'data'.pack('V', 4).pack('v*', 0, 16384);
    $bytes = 'RIFF'.pack('V', strlen($data) + 4).'WAVE'.$data;
    $this->asset = (string) Str::uuid();
    $this->rights = ['source' => 'Original takes', 'license' => 'CC-BY-4.0', 'attribution' => 'Artist'];
    $uploads = app(MediaUploads::class);
    $uploads->start($this->actor, new StartUpload('studio', $this->asset, 'Piano', strlen($bytes), hash('sha256', $bytes), $this->rights));
    $uploads->append($this->actor, 'studio', $this->asset, 0, $bytes);
    $uploads->complete($this->actor, 'studio', $this->asset);
    $this->pack = (string) Str::uuid();
    app(MediaPacks::class)->save($this->actor, new SaveMediaPack('studio', $this->pack, 'Studio pack', [$this->asset], $this->rights, 0, 'save'));
    $this->jobs = app(ArchiveExports::class);
    $this->command = fn (?string $task = null, int $version = 1) => new RequestArchiveExport('studio', $task ?? (string) Str::uuid(), $this->pack, $version);
    $this->key = fn ($id) => 'music/archive-exports/'.hash('sha256', 'studio').'/'.hash('sha256', $id).'.zip';
    $this->events = [];
    app(Events::class)->listen(ArchiveExportChanged::class, function ($event) {
        $this->events[] = $event;
    });
});

it('queues after commit and exports a private verified version-pinned ZIP once', function () {
    $command = ($this->command)();
    DB::transaction(function () use ($command) {
        $task = $this->jobs->request($this->actor, $command);
        expect($task->jsonSerialize()['progress']['completed_steps'])->toBe(0)->and($this->events)->toBe([]);
        Bus::assertNothingDispatched();
    });
    Bus::assertDispatched(ExportPackArchiveTask::class, fn ($job) => $job->taskId === $command->taskId);
    $this->jobs->request($this->actor, $command);
    expect($this->events)->toHaveCount(1)->and($this->jobs->result($this->actor, 'studio', $command->taskId))->toBeNull();
    expect(fn () => $this->jobs->open($this->actor, 'studio', $command->taskId))->toThrow(UploadConflict::class);
    Bus::dispatched(ExportPackArchiveTask::class)->last()->handle($this->jobs);
    $this->jobs->process($command->taskId);
    $result = $this->jobs->result($this->actor, 'studio', $command->taskId);
    $stream = $this->jobs->open($this->actor, 'studio', $command->taskId);
    try {
        $bytes = stream_get_contents($stream);
        expect(strlen($bytes))->toBe($result->bytes)->and(hash('sha256', $bytes))->toBe($result->sha256)->and($result->mediaObjects)->toBe(1);
        $zip = new ZipArchive();
        expect($zip->open(stream_get_meta_data($stream)['uri']))->toBeTrue();
        expect(json_decode($zip->getFromName('manifest.json'), true)['document']['version'])->toBe(1);
        $zip->close();
    } finally {
        fclose($stream);
    }
    expect($this->disk->getVisibility(($this->key)($command->taskId)))->toBe('private')
        ->and($this->jobs->request($this->actor, $command)->status)->toBe('ready')->and($this->events)->toHaveCount(2);
    expect(json_encode($this->jobs->get($this->actor, 'studio', $command->taskId)))->not->toContain('archive-exports', 'object_key', 'fingerprint');
    expect(fn () => $this->jobs->cancel($this->actor, 'studio', $command->taskId))->toThrow(UploadConflict::class);
    $this->jobs->failed($command->taskId);
});

it('cancels without producing an archive and enforces immutable owned identities and live rights', function () {
    $command = ($this->command)();
    $this->jobs->request($this->actor, $command);
    app(MediaPacks::class)->save($this->actor, new SaveMediaPack('studio', $this->pack, 'Second version', [$this->asset], $this->rights, 1, 'save2'));
    expect(fn () => $this->jobs->request($this->actor, ($this->command)($command->taskId, 2)))->toThrow(UploadConflict::class);
    expect(fn () => $this->jobs->get($this->actor, 'other', $command->taskId))->toThrow(RecordNotFoundException::class);
    expect(fn () => $this->jobs->get(new GenericUser(['id' => 2]), 'studio', $command->taskId))->toThrow(RecordNotFoundException::class);
    $this->allowed = false;
    expect(fn () => $this->jobs->get($this->actor, 'studio', $command->taskId))->toThrow(AuthorizationException::class);
    expect(fn () => $this->jobs->process($command->taskId))->toThrow(AuthorizationException::class);
    $this->allowed = true;
    $this->denied = 'pack_distribute';
    expect(fn () => $this->jobs->cancel($this->actor, 'studio', $command->taskId))->toThrow(AuthorizationException::class);
    $this->denied = null;
    expect($this->jobs->cancel($this->actor, 'studio', $command->taskId)->status)->toBe('cancelled')
        ->and($this->jobs->cancel($this->actor, 'studio', $command->taskId)->status)->toBe('cancelled');
    $this->jobs->process($command->taskId);
    $this->jobs->failed($command->taskId);
    expect($this->jobs->request($this->actor, $command)->status)->toBe('cancelled')->and($this->disk->exists(($this->key)($command->taskId)))->toBeFalse();
    expect(fn () => new RequestArchiveExport('', 'bad', 'bad', 0))->toThrow(ValidationException::class);
});

it('rolls back failed acknowledgements then retries without publishing unverified results', function () {
    $command = ($this->command)();
    $this->jobs->request($this->actor, $command);
    DB::unprepared("CREATE TRIGGER ignore_archive_ack BEFORE UPDATE OF status ON music_media_archive_exports WHEN NEW.status = 'ready' BEGIN SELECT RAISE(IGNORE); END");
    expect(fn () => $this->jobs->process($command->taskId))->toThrow(LogicException::class);
    expect($this->jobs->result($this->actor, 'studio', $command->taskId))->toBeNull();
    Bus::dispatched(ExportPackArchiveTask::class)->last()->failed(new RuntimeException('/private/path secret'));
    expect($this->jobs->get($this->actor, 'studio', $command->taskId)->jsonSerialize()['error_code'])->toBe('archive_export_failed');
    expect(fn () => $this->jobs->cancel($this->actor, 'studio', $command->taskId))->toThrow(UploadConflict::class);
    DB::unprepared('DROP TRIGGER ignore_archive_ack');
    expect($this->jobs->request($this->actor, $command)->status)->toBe('queued');
    $this->jobs->process($command->taskId);
    expect($this->jobs->result($this->actor, 'studio', $command->taskId)->packId)->toBe($this->pack);
    $this->jobs->failed((string) Str::uuid());
});

it('rejects missing truncated enlarged and same-sized corrupted private artifacts', function () {
    $command = ($this->command)();
    $this->jobs->request($this->actor, $command);
    $this->jobs->process($command->taskId);
    $key = ($this->key)($command->taskId);
    $bytes = $this->disk->get($key);
    foreach ([substr($bytes, 0, -1), $bytes.'x', str_repeat('x', strlen($bytes))] as $bad) {
        $this->disk->put($key, $bad);
        expect(fn () => $this->jobs->open($this->actor, 'studio', $command->taskId))->toThrow(InvalidAudio::class);
    }
    $this->disk->delete($key);
    expect(fn () => $this->jobs->open($this->actor, 'studio', $command->taskId))->toThrow(InvalidAudio::class);
});

it('expires results and cleans only bounded workspace-owned expired artifacts and old failed staging', function () {
    $command = ($this->command)();
    $this->jobs->request($this->actor, $command);
    $this->jobs->process($command->taskId);
    expect($this->jobs->cleanup($this->actor, 'studio'))->toBe(0);
    $this->travel(3601)->seconds();
    expect($this->jobs->get($this->actor, 'studio', $command->taskId)->jsonSerialize()['error_code'])->toBe('archive_result_expired');
    expect(fn () => $this->jobs->open($this->actor, 'studio', $command->taskId))->toThrow(ArchiveResultExpired::class);
    expect($this->jobs->request($this->actor, $command)->status)->toBe('expired');
    $this->denied = 'archive_cleanup';
    expect(fn () => $this->jobs->cleanup($this->actor, 'studio'))->toThrow(AuthorizationException::class);
    $this->denied = null;
    expect(fn () => $this->jobs->cleanup($this->actor, 'studio', 101))->toThrow(ValidationException::class);
    DB::unprepared("CREATE TRIGGER ignore_archive_cleanup BEFORE UPDATE OF status ON music_media_archive_exports WHEN NEW.status = 'expired' BEGIN SELECT RAISE(IGNORE); END");
    expect(fn () => $this->jobs->cleanup($this->actor, 'studio'))->toThrow(LogicException::class);
    expect($this->disk->exists(($this->key)($command->taskId)))->toBeFalse();
    DB::unprepared('DROP TRIGGER ignore_archive_cleanup');
    expect($this->jobs->cleanup($this->actor, 'other'))->toBe(0)->and($this->jobs->cleanup($this->actor, 'studio', 1))->toBe(1)
        ->and($this->disk->exists(($this->key)($command->taskId)))->toBeFalse()->and($this->jobs->cleanup($this->actor, 'studio'))->toBe(0);
    expect(app(MediaUploads::class)->get($this->actor, 'studio', $this->asset)->id)->toBe($this->asset);
    $cancelled = ($this->command)();
    $this->jobs->request($this->actor, $cancelled);
    $this->jobs->cancel($this->actor, 'studio', $cancelled->taskId);
    $this->travel(86401)->seconds();
    expect($this->jobs->cleanup($this->actor, 'studio'))->toBe(1)->and($this->jobs->request($this->actor, $cancelled)->status)->toBe('expired');
    $this->travelBack();
});

it('fails closed on storage failure cleanup failure and unavailable canonical identities', function () {
    $command = ($this->command)();
    $this->jobs->request($this->actor, $command);
    $this->users->shouldReceive('retrieveById')->with('1')->andReturnNull();
    expect(fn () => $this->jobs->process($command->taskId))->toThrow(AuthorizationException::class);
    app()->when(ArchiveExports::class)->needs(UserProvider::class)->give(fn () => null);
    expect(fn () => app(ArchiveExports::class)->process($command->taskId))->toThrow(AuthorizationException::class);
    app()->when(ArchiveExports::class)->needs(UserProvider::class)->give(fn () => Mockery::mock(UserProvider::class)->shouldReceive('retrieveById')->andReturn($this->actor)->getMock());
    $objects = Mockery::mock(Filesystem::class)->shouldReceive('put')->andReturnFalse()->getMock();
    app()->when(ArchiveExports::class)->needs(Filesystem::class)->give(fn () => $objects);
    expect(fn () => app(ArchiveExports::class)->process($command->taskId))->toThrow(RuntimeException::class);
    $this->jobs->failed($command->taskId);
    $this->travel(86401)->seconds();
    $objects->shouldReceive('exists')->andReturnTrue();
    $objects->shouldReceive('delete')->andReturnFalse();
    expect(fn () => app(ArchiveExports::class)->cleanup($this->actor, 'studio'))->toThrow(RuntimeException::class);
    $this->travelBack();
});

it('supports a real synchronous queue and safely rolls back only its task schema', function () {
    app()->instance(Dispatcher::class, $this->realBus);
    $command = ($this->command)();
    app(ArchiveExports::class)->request($this->actor, $command);
    expect(app(ArchiveExports::class)->get($this->actor, 'studio', $command->taskId)->status)->toBe('ready');
    $migration = require __DIR__.'/../../database/migrations/2026_11_05_234600_create_music_media_archive_exports.php';
    $migration->down();
    expect(Schema::hasTable('music_media_archive_exports'))->toBeFalse()->and(Schema::hasTable('music_media_packs'))->toBeTrue()
        ->and(app(MediaUploads::class)->get($this->actor, 'studio', $this->asset)->id)->toBe($this->asset);
});

it('fences delayed ZIP deliveries and failure callbacks after an explicit retry', function () {
    $command = ($this->command)();
    $this->jobs->request($this->actor, $command);
    $old = Bus::dispatched(ExportPackArchiveTask::class)->last();
    $this->jobs->request($this->actor, $command);
    $queued = Bus::dispatched(ExportPackArchiveTask::class)->last();
    $old->handle($this->jobs);
    $old->failed(new RuntimeException('Outdated queued payload'));
    expect($this->jobs->get($this->actor, 'studio', $command->taskId)->status)->toBe('queued');
    $queued->failed(new RuntimeException('Current delivery exhausted'));
    $this->jobs->request($this->actor, $command);
    $queued->failed(new RuntimeException('Delayed exhausted callback'));
    $queued->handle($this->jobs);
    expect($this->jobs->get($this->actor, 'studio', $command->taskId)->status)->toBe('queued')
        ->and($this->disk->exists(($this->key)($command->taskId)))->toBeFalse();
    Bus::dispatched(ExportPackArchiveTask::class)->last()->handle($this->jobs);
    $stream = $this->jobs->open($this->actor, 'studio', $command->taskId);
    try {
        expect(hash('sha256', stream_get_contents($stream)))->toBe($this->jobs->result($this->actor, 'studio', $command->taskId)->sha256);
    } finally {
        fclose($stream);
    }
});

it('ignores pre-upgrade serialized archive payloads until a fenced request is dispatched', function () {
    $command = ($this->command)();
    $this->jobs->request($this->actor, $command);
    $class = ExportPackArchiveTask::class;
    $legacy = unserialize(sprintf('O:%d:"%s":1:{s:6:"taskId";s:36:"%s";}', strlen($class), $class, $command->taskId));
    $legacy->handle($this->jobs);
    $legacy->failed(new RuntimeException('Legacy failure'));
    expect($this->jobs->get($this->actor, 'studio', $command->taskId)->status)->toBe('queued')
        ->and($this->disk->exists(($this->key)($command->taskId)))->toBeFalse();
    Bus::dispatched(ExportPackArchiveTask::class)->last()->handle($this->jobs);
    expect($this->jobs->get($this->actor, 'studio', $command->taskId)->status)->toBe('ready');
});

it('preserves legacy derived tasks and recordings across dispatch migration rollback and upgrade', function () {
    $command = ($this->command)();
    $this->jobs->request($this->actor, $command);
    $old = Bus::dispatched(ExportPackArchiveTask::class)->last();
    $migration = require __DIR__.'/../../database/migrations/2026_11_13_215104_add_dispatch_identity_to_music_media_derived_tasks.php';
    $migration->down();
    expect(Schema::hasColumn('music_media_waveform_tasks', 'dispatch_id'))->toBeFalse()
        ->and(Schema::hasColumn('music_media_archive_exports', 'dispatch_id'))->toBeFalse()
        ->and(DB::table('music_media_archive_exports')->where('id', $command->taskId)->value('status'))->toBe('queued')
        ->and(app(MediaUploads::class)->get($this->actor, 'studio', $this->asset)->id)->toBe($this->asset);
    $migration->up();
    $old->handle($this->jobs);
    $old->failed(new RuntimeException('Stale generation'));
    expect($this->jobs->get($this->actor, 'studio', $command->taskId)->status)->toBe('queued');
    $this->jobs->request($this->actor, $command);
    Bus::dispatched(ExportPackArchiveTask::class)->last()->handle($this->jobs);
    expect($this->jobs->get($this->actor, 'studio', $command->taskId)->status)->toBe('ready');
});
