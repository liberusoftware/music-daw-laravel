<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\RecordNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Liberu\Foundation\Files\Contracts\MalwareScanner;
use Liberu\Foundation\Files\Contracts\MediaAccess;
use Liberu\Foundation\ImportExport\Contracts\TransferAuthorizer;
use Liberu\Music\MediaLibrary\Commands\CollectCancelledUploads;
use Liberu\Music\MediaLibrary\Commands\StartUpload;
use Liberu\Music\MediaLibrary\Contracts\MediaAuthorizer;
use Liberu\Music\MediaLibrary\Events\AssetReady;
use Liberu\Music\MediaLibrary\Exceptions\InvalidAudio;
use Liberu\Music\MediaLibrary\Exceptions\UploadBudgetExceeded;
use Liberu\Music\MediaLibrary\Exceptions\UploadConflict;
use Liberu\Music\MediaLibrary\MusicMediaLibraryServiceProvider;
use Liberu\Music\MediaLibrary\Services\AssetWaveforms;
use Liberu\Music\MediaLibrary\Services\ContentLocks;
use Liberu\Music\MediaLibrary\Services\MediaDownloads;
use Liberu\Music\MediaLibrary\Services\MediaUploads;
use Liberu\Music\MediaLibrary\Services\QuarantineCleanup;
use Liberu\Music\MediaLibrary\Services\UploadBudgets;
use Liberu\Music\MediaLibrary\Services\WaveformIndexer;
use Liberu\Music\MediaLibrary\Services\WaveInspector;
use Liberu\Music\MediaLibrary\Support\GateMediaAuthorizer;
use Liberu\Music\MediaLibrary\Support\ScopedMediaAccess;

function uploadAudioFixture(): string
{
    $format = pack('vvVVvv', 1, 2, 48000, 192000, 4, 16);
    $data = pack('v*', 0, 32767, 65535, 16384);
    $chunks = 'fmt '.pack('V', 16).$format.'data'.pack('V', strlen($data)).$data;

    return 'RIFF'.pack('V', strlen($chunks) + 4).'WAVE'.$chunks;
}

beforeEach(function () {
    $this->artisan('migrate', ['--path' => realpath(__DIR__.'/../../database/migrations'), '--realpath' => true])->assertSuccessful();
    config()->set('music-media.disk', 'music-uploads-test');
    $this->disk = Storage::fake('music-uploads-test');
    $this->actor = new GenericUser(['id' => 7]);
    $this->id = (string) Str::uuid();
    $this->bytes = uploadAudioFixture();
    $this->command = fn (?string $id = null, string $workspace = 'studio') => new StartUpload($workspace, $id ?? $this->id, 'Recording.wav', strlen($this->bytes), hash('sha256', $this->bytes), ['license' => 'original', 'attribution' => 'Artist']);
    app(Gate::class)->define('music.media.upload', fn ($actor, $workspace, $id) => $actor->id === 7 && in_array($workspace, ['studio', 'other']));
    app(Gate::class)->define('music.media.read', fn ($actor, $workspace, $id) => $actor->id === 7 && in_array($workspace, ['studio', 'other']));
    $this->scanner = Mockery::mock(MalwareScanner::class);
    $this->scanner->shouldReceive('clean')->andReturnUsing(fn ($path) => is_file($path))->byDefault();
    app()->instance(MalwareScanner::class, $this->scanner);
    $this->uploads = app(MediaUploads::class);
    $this->events = [];
    app(Dispatcher::class)->listen(AssetReady::class, function ($event) {
        $this->events[] = $event;
    });
    $this->receive = function (): void {
        $this->uploads->start($this->actor, ($this->command)());
        $this->uploads->append($this->actor, 'studio', $this->id, 0, $this->bytes);
    };
});

it('resumes chunks across service instances and publishes only verified complete private audio', function () {
    $started = $this->uploads->start($this->actor, ($this->command)());
    expect($started->status)->toBe('uploading')->and($started->receivedBytes)->toBe(0)->and($started->totalBytes)->toBe(strlen($this->bytes));
    $part = substr($this->bytes, 0, 20);
    $progress = $this->uploads->append($this->actor, 'studio', $this->id, 0, $part);
    expect($progress->receivedBytes)->toBe(20);
    expect(fn () => $this->uploads->get($this->actor, 'studio', $this->id))->toThrow(RecordNotFoundException::class);
    expect(fn () => $this->uploads->complete($this->actor, 'studio', $this->id))->toThrow(UploadConflict::class);
    $resumed = app(MediaUploads::class);
    expect($resumed->status($this->actor, 'studio', $this->id)->receivedBytes)->toBe(20);
    $resumed->append($this->actor, 'studio', $this->id, 20, substr($this->bytes, 20));
    $asset = $resumed->complete($this->actor, 'studio', $this->id);
    expect($asset->reference())->toBe(['id' => $this->id, 'sha256' => hash('sha256', $this->bytes), 'sample_rate' => 48000, 'channels' => 2, 'frames' => 2])
        ->and($asset->name)->toBe('Recording.wav')->and($asset->workspaceId)->toBe('studio')
        ->and($asset->provenance)->toBe(['license' => 'original', 'attribution' => 'Artist'])
        ->and($asset->metadata['schema_version'])->toBe(1)->and($asset->duplicateOf)->toBeNull();
    expect($resumed->get($this->actor, 'studio', $this->id)->reference())->toBe($asset->reference())
        ->and($resumed->complete($this->actor, 'studio', $this->id)->reference())->toBe($asset->reference());
    expect($this->events)->toHaveCount(1)->and($this->events[0]->actorId)->toBe('7')->and($this->events[0]->asset->id)->toBe($this->id);
    $key = DB::table('music_media_uploads')->value('blob_key');
    expect($this->disk->get($key))->toBe($this->bytes)->and($this->disk->getVisibility($key))->toBe('private')
        ->and($resumed->status($this->actor, 'studio', $this->id)->status)->toBe('ready');
});

it('makes upload and chunk replay idempotent while rejecting changed declarations and offsets', function () {
    ($this->receive)();
    expect($this->uploads->start($this->actor, ($this->command)())->receivedBytes)->toBe(strlen($this->bytes));
    expect($this->uploads->append($this->actor, 'studio', $this->id, 0, $this->bytes)->receivedBytes)->toBe(strlen($this->bytes));
    expect(DB::table('music_media_upload_chunks')->count())->toBe(1);
    expect(fn () => $this->uploads->start($this->actor, new StartUpload('studio', $this->id, 'Changed', strlen($this->bytes), hash('sha256', $this->bytes))))->toThrow(UploadConflict::class);
    expect(fn () => $this->uploads->append($this->actor, 'studio', $this->id, 0, str_repeat('x', strlen($this->bytes))))->toThrow(UploadConflict::class);
    expect(fn () => $this->uploads->append($this->actor, 'studio', $this->id, 10, 'new'))->toThrow(UploadConflict::class);
    expect(fn () => $this->uploads->append($this->actor, 'studio', $this->id, strlen($this->bytes), 'x'))->toThrow(UploadConflict::class);
    $this->uploads->complete($this->actor, 'studio', $this->id);
    expect($this->uploads->append($this->actor, 'studio', $this->id, 0, $this->bytes)->status)->toBe('ready');
    expect(fn () => $this->uploads->append($this->actor, 'studio', $this->id, 1, 'x'))->toThrow(UploadConflict::class);
    expect(fn () => $this->uploads->cancel($this->actor, 'studio', $this->id))->toThrow(UploadConflict::class);
});

it('detects workspace scoped duplicates while preserving each import provenance', function () {
    ($this->receive)();
    $first = $this->uploads->complete($this->actor, 'studio', $this->id);
    $secondId = (string) Str::uuid();
    $secondCommand = new StartUpload('studio', $secondId, 'Second import', strlen($this->bytes), hash('sha256', $this->bytes), ['source' => 'Second source']);
    $this->uploads->start($this->actor, $secondCommand);
    $this->uploads->append($this->actor, 'studio', $secondId, 0, $this->bytes);
    $second = $this->uploads->complete($this->actor, 'studio', $secondId);
    expect($second->duplicateOf)->toBe($first->id)->and($second->provenance)->toBe(['source' => 'Second source']);
    $thirdId = (string) Str::uuid();
    $this->uploads->start($this->actor, ($this->command)($thirdId, 'other'));
    $this->uploads->append($this->actor, 'other', $thirdId, 0, $this->bytes);
    $third = $this->uploads->complete($this->actor, 'other', $thirdId);
    expect($third->duplicateOf)->toBeNull();
    expect(DB::table('music_media_uploads')->where('workspace_id', 'studio')->distinct()->count('blob_key'))->toBe(1)
        ->and(DB::table('music_media_uploads')->distinct()->count('blob_key'))->toBe(2);
});

it('reauthorizes reads, retries, mutations and completion after revocation', function () {
    ($this->receive)();
    $outsider = new GenericUser(['id' => 8]);
    foreach ([
        fn () => $this->uploads->start($outsider, ($this->command)()),
        fn () => $this->uploads->status($outsider, 'studio', $this->id),
        fn () => $this->uploads->append($outsider, 'studio', $this->id, 0, $this->bytes),
        fn () => $this->uploads->complete($outsider, 'studio', $this->id),
        fn () => $this->uploads->cancel($outsider, 'studio', $this->id),
        fn () => $this->uploads->get($outsider, 'studio', $this->id),
    ] as $action) {
        expect($action)->toThrow(AuthorizationException::class);
    }
    $this->uploads->complete($this->actor, 'studio', $this->id);
    app(Gate::class)->define('music.media.upload', fn () => false);
    app(Gate::class)->define('music.media.read', fn () => false);
    expect(fn () => $this->uploads->complete($this->actor, 'studio', $this->id))->toThrow(AuthorizationException::class);
    expect(fn () => $this->uploads->get($this->actor, 'studio', $this->id))->toThrow(AuthorizationException::class);
    expect($this->events)->toHaveCount(1);
});

it('does not cross workspace boundaries even when the actor may access both workspaces', function () {
    ($this->receive)();
    expect(fn () => $this->uploads->status($this->actor, 'other', $this->id))->toThrow(RecordNotFoundException::class);
    expect(fn () => $this->uploads->complete($this->actor, 'other', $this->id))->toThrow(RecordNotFoundException::class);
    expect(fn () => $this->uploads->start($this->actor, ($this->command)($this->id, 'other')))->toThrow(RecordNotFoundException::class);
    expect(DB::table('music_media_uploads')->count())->toBe(1);
});

it('cancels an unpublished upload idempotently without deleting content or exposing it', function () {
    ($this->receive)();
    $files = $this->disk->allFiles();
    expect($this->uploads->cancel($this->actor, 'studio', $this->id)->status)->toBe('cancelled');
    expect($this->uploads->cancel($this->actor, 'studio', $this->id)->status)->toBe('cancelled');
    expect(fn () => $this->uploads->append($this->actor, 'studio', $this->id, 0, $this->bytes))->toThrow(UploadConflict::class);
    expect(fn () => $this->uploads->complete($this->actor, 'studio', $this->id))->toThrow(UploadConflict::class);
    expect(fn () => $this->uploads->get($this->actor, 'studio', $this->id))->toThrow(RecordNotFoundException::class);
    expect($this->disk->allFiles())->toBe($files)->and($this->events)->toBe([]);
});

it('fails closed when scanning is absent, rejected or unavailable and permits later retry', function () {
    ($this->receive)();
    $withoutScanner = new MediaUploads(DB::connection(), $this->disk, app(MediaAuthorizer::class), app(WaveInspector::class), app(Dispatcher::class), app(UploadBudgets::class), app(ContentLocks::class));
    expect(fn () => $withoutScanner->complete($this->actor, 'studio', $this->id))->toThrow(InvalidAudio::class, 'quarantined');
    $attempt = 0;
    $this->scanner->shouldReceive('clean')->times(3)->andReturnUsing(function () use (&$attempt) {
        return match (++$attempt) {
            1 => false,
            2 => throw new RuntimeException('Scanner unavailable'),
            default => true,
        };
    });
    expect(fn () => $this->uploads->complete($this->actor, 'studio', $this->id))->toThrow(InvalidAudio::class, 'quarantined');
    expect(fn () => $this->uploads->complete($this->actor, 'studio', $this->id))->toThrow(RuntimeException::class, 'unavailable');
    expect($this->uploads->status($this->actor, 'studio', $this->id)->status)->toBe('uploading')->and($this->events)->toBe([]);
    $this->uploads->complete($this->actor, 'studio', $this->id);
    expect($this->events)->toHaveCount(1);
});

it('checks bytes after scanning and refuses declared checksum mismatches', function () {
    $command = new StartUpload('studio', $this->id, 'Bad hash', strlen($this->bytes), str_repeat('0', 64));
    $this->uploads->start($this->actor, $command);
    $this->uploads->append($this->actor, 'studio', $this->id, 0, $this->bytes);
    expect(fn () => $this->uploads->complete($this->actor, 'studio', $this->id))->toThrow(InvalidAudio::class, 'checksum');
    expect($this->uploads->status($this->actor, 'studio', $this->id)->status)->toBe('uploading');
});

it('refuses a malformed audio payload even with a matching content checksum', function () {
    $this->bytes = str_repeat('x', 52);
    ($this->receive)();
    expect(fn () => $this->uploads->complete($this->actor, 'studio', $this->id))->toThrow(InvalidAudio::class, 'container');
    expect($this->events)->toBe([]);
});

it('detects missing, truncated or displaced chunks without publishing a revision', function () {
    ($this->receive)();
    $key = DB::table('music_media_upload_chunks')->value('object_key');
    $this->disk->put($key, substr($this->bytes, 0, -1));
    expect(fn () => $this->uploads->complete($this->actor, 'studio', $this->id))->toThrow(InvalidAudio::class, 'chunk is incomplete');
    $this->disk->put($key, $this->bytes);
    DB::table('music_media_upload_chunks')->update(['offset_bytes' => 1]);
    expect(fn () => $this->uploads->complete($this->actor, 'studio', $this->id))->toThrow(InvalidAudio::class, 'gap');
    DB::table('music_media_upload_chunks')->delete();
    expect(fn () => $this->uploads->complete($this->actor, 'studio', $this->id))->toThrow(InvalidAudio::class, 'assembled');
    expect($this->events)->toBe([]);
});

it('does not mark a chunk received when object storage fails', function () {
    $store = Mockery::mock(Filesystem::class);
    $store->shouldReceive('put')->once()->andReturn(false);
    $uploads = new MediaUploads(DB::connection(), $store, app(MediaAuthorizer::class), app(WaveInspector::class), app(Dispatcher::class), app(UploadBudgets::class), app(ContentLocks::class), $this->scanner);
    $uploads->start($this->actor, ($this->command)());
    expect(fn () => $uploads->append($this->actor, 'studio', $this->id, 0, $this->bytes))->toThrow(RuntimeException::class, 'chunk could not');
    expect($uploads->status($this->actor, 'studio', $this->id)->receivedBytes)->toBe(0)
        ->and(DB::table('music_media_upload_chunks')->count())->toBe(0);
});

it('does not publish an asset when private storage cannot read or save the verified bytes', function () {
    ($this->receive)();
    $store = Mockery::mock($this->disk);
    $store->shouldReceive('readStream')->once()->andReturn(false);
    $uploads = new MediaUploads(DB::connection(), $store, app(MediaAuthorizer::class), app(WaveInspector::class), app(Dispatcher::class), app(UploadBudgets::class), app(ContentLocks::class), $this->scanner);
    expect(fn () => $uploads->complete($this->actor, 'studio', $this->id))->toThrow(InvalidAudio::class, 'unreadable');
    $store = Mockery::mock($this->disk);
    $store->shouldReceive('put')->withArgs(fn ($key, $stream, $options) => str_starts_with($key, 'music/assets/'))->once()->andReturn(false);
    $uploads = new MediaUploads(DB::connection(), $store, app(MediaAuthorizer::class), app(WaveInspector::class), app(Dispatcher::class), app(UploadBudgets::class), app(ContentLocks::class), $this->scanner);
    expect(fn () => $uploads->complete($this->actor, 'studio', $this->id))->toThrow(RuntimeException::class, 'asset could not');
    expect($this->uploads->status($this->actor, 'studio', $this->id)->status)->toBe('uploading')->and($this->events)->toBe([]);
});

it('refuses corrupt existing content-addressed objects and missing ready objects', function () {
    ($this->receive)();
    $key = 'music/assets/'.hash('sha256', 'studio').'/'.hash('sha256', $this->bytes);
    $this->disk->put($key, str_repeat('x', strlen($this->bytes)));
    expect(fn () => $this->uploads->complete($this->actor, 'studio', $this->id))->toThrow(InvalidAudio::class, 'integrity');
    $this->disk->delete($key);
    $this->uploads->complete($this->actor, 'studio', $this->id);
    $this->disk->delete($key);
    expect(fn () => $this->uploads->get($this->actor, 'studio', $this->id))->toThrow(InvalidAudio::class, 'missing');
    expect(fn () => $this->uploads->complete($this->actor, 'studio', $this->id))->toThrow(InvalidAudio::class, 'missing');
});

it('does not emit a ready event or retain published metadata after a database rollback', function () {
    ($this->receive)();
    DB::beginTransaction();
    $this->uploads->complete($this->actor, 'studio', $this->id);
    expect($this->events)->toBe([]);
    DB::rollBack();
    expect($this->uploads->status($this->actor, 'studio', $this->id)->status)->toBe('uploading')->and($this->events)->toBe([]);
    $this->uploads->complete($this->actor, 'studio', $this->id);
    expect($this->events)->toHaveCount(1);
});

it('validates upload declarations and chunk bounds before persisting anything', function () {
    foreach ([['', $this->id, 'Name', 52, hash('sha256', $this->bytes)], ['studio', 'bad', 'Name', 52, hash('sha256', $this->bytes)], ['studio', $this->id, '', 52, hash('sha256', $this->bytes)], ['studio', $this->id, 'Name', 43, hash('sha256', $this->bytes)], ['studio', $this->id, 'Name', 1073741825, hash('sha256', $this->bytes)], ['studio', $this->id, 'Name', 52, 'bad']] as [$workspace, $id, $name, $bytes, $hash]) {
        expect(fn () => new StartUpload($workspace, $id, $name, $bytes, $hash))->toThrow(ValidationException::class);
    }
    expect(fn () => new StartUpload('studio', $this->id, 'Name', 52, hash('sha256', $this->bytes), ['secret' => 'not allowed']))->toThrow(ValidationException::class);
    $this->uploads->start($this->actor, ($this->command)());
    foreach ([[-1, 'a'], [0, ''], [0, str_repeat('x', 4194305)]] as [$offset, $data]) {
        expect(fn () => $this->uploads->append($this->actor, 'studio', $this->id, $offset, $data))->toThrow(ValidationException::class);
    }
    expect(DB::table('music_media_upload_chunks')->count())->toBe(0);
});

it('bounds chunk count to avoid an unbounded assembly directory', function () {
    $this->uploads->start($this->actor, ($this->command)());
    $rows = [];
    for ($index = 1; $index <= 4096; $index++) {
        $rows[] = ['upload_id' => $this->id, 'offset_bytes' => $index, 'bytes' => 1, 'sha256' => str_repeat('a', 64), 'object_key' => 'test'];
    }
    DB::table('music_media_upload_chunks')->insert($rows);
    expect(fn () => $this->uploads->append($this->actor, 'studio', $this->id, 0, 'a'))->toThrow(UploadConflict::class);
});

it('keeps authorizer bindings replaceable and reverses only an explicit schema rollback', function () {
    expect(app(MediaAuthorizer::class))->toBeInstanceOf(GateMediaAuthorizer::class);
    $replacement = Mockery::mock(MediaAuthorizer::class);
    app()->instance(MediaAuthorizer::class, $replacement);
    (new MusicMediaLibraryServiceProvider(app()))->register();
    expect(app(MediaAuthorizer::class))->toBe($replacement);
    $this->artisan('migrate:rollback', ['--path' => realpath(__DIR__.'/../../database/migrations'), '--realpath' => true])->assertSuccessful();
    expect(Schema::hasTable('music_media_uploads'))->toBeFalse()->and(Schema::hasTable('music_media_upload_chunks'))->toBeFalse();
});

it('restores a lost receiving chunk on matching replay and reports failed recovery honestly', function () {
    ($this->receive)();
    $key = DB::table('music_media_upload_chunks')->value('object_key');
    $this->disk->delete($key);
    $store = Mockery::mock($this->disk);
    $store->shouldReceive('put')->once()->andReturn(false);
    $uploads = new MediaUploads(DB::connection(), $store, app(MediaAuthorizer::class), app(WaveInspector::class), app(Dispatcher::class), app(UploadBudgets::class), app(ContentLocks::class), $this->scanner);
    expect(fn () => $uploads->append($this->actor, 'studio', $this->id, 0, $this->bytes))->toThrow(RuntimeException::class, 'restored');
    $this->uploads->append($this->actor, 'studio', $this->id, 0, $this->bytes);
    expect($this->disk->get($key))->toBe($this->bytes)
        ->and(DB::table('music_media_upload_chunks')->count())->toBe(1)
        ->and($this->uploads->complete($this->actor, 'studio', $this->id)->reference()['frames'])->toBe(2);
});

it('reads durable upload progress in one indexed database query', function () {
    ($this->receive)();
    DB::enableQueryLog();
    DB::flushQueryLog();
    $progress = $this->uploads->status($this->actor, 'studio', $this->id);
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    expect($progress->receivedBytes)->toBe(strlen($this->bytes))->and($queries)->toHaveCount(1);
});

it('indexes only authorized ready assets and retains explicit channel and frame positions', function () {
    ($this->receive)();
    $waveforms = app(AssetWaveforms::class);
    expect(fn () => $waveforms->build($this->actor, 'studio', $this->id))->toThrow(RecordNotFoundException::class);
    $this->uploads->complete($this->actor, 'studio', $this->id);
    $waveform = $waveforms->build($this->actor, 'studio', $this->id, 1);
    expect($waveform->audio->sha256)->toBe(hash('sha256', $this->bytes))->and($waveform->peaks)->toHaveCount(2)
        ->and($waveform->peaks[0]['start_sample_frame'])->toBe(0)->and($waveform->peaks[1]['end_sample_frame'])->toBe(2)
        ->and($waveform->peaks[0]['channels'][0])->toBe(['minimum' => 0.0, 'maximum' => 0.0])
        ->and($waveform->peaks[1]['channels'][1])->toBe(['minimum' => 0.5, 'maximum' => 0.5]);
    expect(fn () => $waveforms->build($this->actor, 'other', $this->id))->toThrow(RecordNotFoundException::class);
    app(Gate::class)->define('music.media.read', fn () => false);
    expect(fn () => $waveforms->build($this->actor, 'studio', $this->id))->toThrow(AuthorizationException::class);
});

it('refuses waveform reads when the private asset stream is unavailable', function () {
    ($this->receive)();
    $this->uploads->complete($this->actor, 'studio', $this->id);
    $store = Mockery::mock(Filesystem::class);
    $store->shouldReceive('readStream')->once()->andReturn(false);
    $waveforms = new AssetWaveforms($this->uploads, DB::connection(), $store, app(WaveformIndexer::class));
    expect(fn () => $waveforms->build($this->actor, 'studio', $this->id))->toThrow(InvalidAudio::class, 'could not be opened');
});

it('refuses previews from a changed published blob and always closes the source stream', function () {
    ($this->receive)();
    $this->uploads->complete($this->actor, 'studio', $this->id);
    $key = DB::table('music_media_uploads')->value('blob_key');
    $this->disk->put($key, substr_replace($this->bytes, pack('v', 12345), 44, 2));
    $stream = $this->disk->readStream($key);
    $store = Mockery::mock(Filesystem::class);
    $store->shouldReceive('readStream')->once()->andReturn($stream);
    $waveforms = new AssetWaveforms($this->uploads, DB::connection(), $store, app(WaveformIndexer::class));
    expect(fn () => $waveforms->build($this->actor, 'studio', $this->id))->toThrow(InvalidAudio::class, 'differs from the verified');
    expect(is_resource($stream))->toBeFalse();
    $this->disk->delete($key);
    expect(fn () => app(AssetWaveforms::class)->build($this->actor, 'studio', $this->id))->toThrow(InvalidAudio::class, 'missing');
});

it('authorizes foundation media reads through the configured principal provider without trusting an ambient login', function () {
    ($this->receive)();
    $users = Mockery::mock(UserProvider::class);
    $users->shouldReceive('retrieveById')->with(7)->andReturn($this->actor);
    $users->shouldReceive('retrieveById')->with('7')->andReturn($this->actor);
    $users->shouldReceive('retrieveById')->with(99)->andReturn(null);
    $users->shouldReceive('retrieveById')->with(8)->andReturn(new GenericUser(['id' => 8]));
    $access = new ScopedMediaAccess(DB::connection(), $users, app(MediaAuthorizer::class));
    $unconfigured = new ScopedMediaAccess(DB::connection(), null, app(MediaAuthorizer::class));
    expect($unconfigured->authorized(7, $this->id, 'read'))->toBeFalse();
    expect($access->authorized(null, $this->id, 'read'))->toBeFalse()
        ->and($access->authorized(7, $this->id, 'delete'))->toBeFalse()
        ->and($access->authorized(99, $this->id, 'read'))->toBeFalse()
        ->and($access->authorized(7, $this->id, 'read'))->toBeFalse();
    $this->uploads->complete($this->actor, 'studio', $this->id);
    expect($access->authorized(7, $this->id, 'read'))->toBeTrue()
        ->and($access->authorized('7', $this->id, 'read'))->toBeTrue()
        ->and($access->authorized(8, $this->id, 'read'))->toBeFalse()
        ->and($access->authorized(7, (string) Str::uuid(), 'read'))->toBeFalse();
    app(Gate::class)->define('music.media.read', fn () => false);
    expect($access->authorized(7, $this->id, 'read'))->toBeFalse();
    expect(app(ScopedMediaAccess::class))->toBeInstanceOf(ScopedMediaAccess::class)
        ->and(app()->bound(MediaAccess::class))->toBeFalse();
});

it('opens an integrity-verified download snapshot only with live domain and foundation transfer authorization', function () {
    ($this->receive)();
    $this->uploads->complete($this->actor, 'studio', $this->id);
    app(Gate::class)->define('music.media.download', fn () => true);
    $downloads = app(MediaDownloads::class);
    expect(fn () => $downloads->open($this->actor, 'studio', $this->id))->toThrow(AuthorizationException::class);
    $allowed = false;
    $transfers = Mockery::mock(TransferAuthorizer::class);
    $transfers->shouldReceive('allowed')->with('7', 'music-media-asset', 'export')->andReturnUsing(function () use (&$allowed) {
        return $allowed;
    });
    app()->instance(TransferAuthorizer::class, $transfers);
    $downloads = app(MediaDownloads::class);
    expect(fn () => $downloads->open($this->actor, 'studio', $this->id))->toThrow(AuthorizationException::class);
    $allowed = true;
    $stream = $downloads->open($this->actor, 'studio', $this->id);
    $key = DB::table('music_media_uploads')->where('id', $this->id)->value('blob_key');
    $this->disk->put($key, 'changed after opening');
    try {
        expect(stream_get_contents($stream))->toBe($this->bytes);
        rewind($stream);
        expect(stream_get_contents($stream))->toBe($this->bytes);
    } finally {
        fclose($stream);
    }
    app(Gate::class)->define('music.media.download', fn () => false);
    expect(fn () => $downloads->open($this->actor, 'studio', $this->id))->toThrow(AuthorizationException::class);
    app(Gate::class)->define('music.media.download', fn () => true);
    app(Gate::class)->define('music.media.read', fn () => false);
    expect(fn () => $downloads->open($this->actor, 'studio', $this->id))->toThrow(AuthorizationException::class);
});

it('rejects unverified unavailable cross-workspace and corrupted downloadable media', function () {
    app(Gate::class)->define('music.media.download', fn () => true);
    app()->instance(TransferAuthorizer::class,
        Mockery::mock(TransferAuthorizer::class)->shouldReceive('allowed')->andReturn(true)->getMock());
    $downloads = app(MediaDownloads::class);
    ($this->receive)();
    expect(fn () => $downloads->open($this->actor, 'studio', $this->id))->toThrow(RecordNotFoundException::class);
    $this->uploads->complete($this->actor, 'studio', $this->id);
    expect(fn () => $downloads->open($this->actor, 'other', $this->id))->toThrow(RecordNotFoundException::class);
    $key = DB::table('music_media_uploads')->where('id', $this->id)->value('blob_key');
    foreach ([substr($this->bytes, 0, -1), $this->bytes.'x', str_repeat('x', strlen($this->bytes))] as $bad) {
        $this->disk->put($key, $bad);
        expect(fn () => $downloads->open($this->actor, 'studio', $this->id))->toThrow(InvalidAudio::class);
    }
    $this->disk->delete($key);
    expect(fn () => $downloads->open($this->actor, 'studio', $this->id))->toThrow(InvalidAudio::class);
    DB::table('music_media_uploads')->where('id', $this->id)->update(['status' => 'trashed']);
    expect(fn () => $downloads->open($this->actor, 'studio', $this->id))->toThrow(RecordNotFoundException::class);
});

describe('private resumable archive inputs', function () {
    beforeEach(function () {
        $file = tmpfile();
        $path = stream_get_meta_data($file)['uri'];
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('manifest.json', '{"format":"untrusted"}');
        $zip->close();
        $this->archiveBytes = file_get_contents($path);
        fclose($file);
        $this->archiveId = (string) Str::uuid();
        $this->archiveCommand = fn (?string $checksum = null) => new StartUpload('studio', $this->archiveId, 'Import.zip', strlen($this->archiveBytes), $checksum ?? hash('sha256', $this->archiveBytes), purpose: 'archive');
        $this->receiveArchive = function (): void {
            $this->uploads->start($this->actor, ($this->archiveCommand)());
            $this->uploads->append($this->actor, 'studio', $this->archiveId, 0, $this->archiveBytes);
        };
    });

    it('resumes private chunks and opens only a scanned checksum-verified owned snapshot without publishing audio', function () {
        $this->uploads->start($this->actor, ($this->archiveCommand)());
        expect(fn () => $this->uploads->openArchive($this->actor, 'studio', $this->archiveId))->toThrow(UploadConflict::class);
        $this->uploads->append($this->actor, 'studio', $this->archiveId, 0, substr($this->archiveBytes, 0, 20));
        $resumed = app(MediaUploads::class);
        $resumed->append($this->actor, 'studio', $this->archiveId, 20, substr($this->archiveBytes, 20));
        $resumed->append($this->actor, 'studio', $this->archiveId, 20, substr($this->archiveBytes, 20));
        $stream = $resumed->openArchive($this->actor, 'studio', $this->archiveId);
        try {
            expect(stream_get_contents($stream))->toBe($this->archiveBytes);
            rewind($stream);
            expect(hash('sha256', stream_get_contents($stream)))->toBe(hash('sha256', $this->archiveBytes));
        } finally {
            fclose($stream);
        }
        expect($resumed->status($this->actor, 'studio', $this->archiveId)->status)->toBe('uploading');
        expect(fn () => $resumed->complete($this->actor, 'studio', $this->archiveId))->toThrow(UploadConflict::class, 'cannot be published as audio');
        expect(fn () => $resumed->get($this->actor, 'studio', $this->archiveId))->toThrow(RecordNotFoundException::class);
        expect(DB::table('music_media_uploads')->where('id', $this->archiveId)->value('blob_key'))->toBeNull();
        $resumed->cancel($this->actor, 'studio', $this->archiveId);
        expect(fn () => $resumed->openArchive($this->actor, 'studio', $this->archiveId))->toThrow(UploadConflict::class);
    });

    it('rejects revoked access foreign ownership wrong workspace and audio-purpose inputs', function () {
        ($this->receiveArchive)();
        expect(fn () => $this->uploads->openArchive($this->actor, 'other', $this->archiveId))->toThrow(RecordNotFoundException::class);
        app(Gate::class)->define('music.media.upload', fn () => true);
        expect(fn () => $this->uploads->openArchive(new GenericUser(['id' => 8]), 'studio', $this->archiveId))->toThrow(RecordNotFoundException::class);
        ($this->receive)();
        expect(fn () => $this->uploads->openArchive($this->actor, 'studio', $this->id))->toThrow(RecordNotFoundException::class);
        app(Gate::class)->define('music.media.upload', fn () => false);
        expect(fn () => $this->uploads->openArchive($this->actor, 'studio', $this->archiveId))->toThrow(AuthorizationException::class);
    });

    it('rejects tampered archive bytes missing chunks and scanner denial before yielding a resource', function () {
        ($this->receiveArchive)();
        $key = DB::table('music_media_upload_chunks')->where('upload_id', $this->archiveId)->value('object_key');
        $this->disk->put($key, str_repeat('x', strlen($this->archiveBytes)));
        expect(fn () => $this->uploads->openArchive($this->actor, 'studio', $this->archiveId))->toThrow(InvalidAudio::class, 'checksum');
        $this->disk->delete($key);
        expect(fn () => $this->uploads->openArchive($this->actor, 'studio', $this->archiveId))->toThrow(InvalidAudio::class);
        $this->uploads->append($this->actor, 'studio', $this->archiveId, 0, $this->archiveBytes);
        $this->scanner->shouldReceive('clean')->andReturnFalse();
        expect(fn () => $this->uploads->openArchive($this->actor, 'studio', $this->archiveId))->toThrow(InvalidAudio::class, 'quarantined');
        expect($this->uploads->status($this->actor, 'studio', $this->archiveId)->status)->toBe('uploading');
    });

    it('preserves legacy audio idempotency while binding archive purpose into command identity', function () {
        $command = ($this->command)();
        $this->uploads->start($this->actor, $command);
        $legacy = ['workspaceId' => 'studio', 'uploadId' => $this->id, 'name' => 'Recording.wav', 'bytes' => strlen($this->bytes),
            'sha256' => hash('sha256', $this->bytes), 'provenance' => ['license' => 'original', 'attribution' => 'Artist']];
        DB::table('music_media_uploads')->where('id', $this->id)->update(['fingerprint' => hash('sha256', json_encode(['7', $legacy], JSON_THROW_ON_ERROR))]);
        expect($this->uploads->start($this->actor, $command)->status)->toBe('uploading');
        $archive = new StartUpload('studio', $this->id, $command->name, $command->bytes, $command->sha256, $command->provenance, 'archive');
        expect(fn () => $this->uploads->start($this->actor, $archive))->toThrow(UploadConflict::class);
    });

    it('bounds archive inputs with container overhead while preserving the audio ceiling', function () {
        $large = new StartUpload('studio', $this->archiveId, 'Large.zip', 1073741825, str_repeat('a', 64), purpose: 'archive');
        expect($this->uploads->start($this->actor, $large)->totalBytes)->toBe(1073741825);
        expect(fn () => new StartUpload('studio', (string) Str::uuid(), 'Audio', 1073741825, str_repeat('a', 64)))->toThrow(ValidationException::class);
        config()->set('music-media.archive_max_audio_bytes', 17179869184);
        expect((new StartUpload('studio', (string) Str::uuid(), 'Maximum.zip', 17196646400, str_repeat('a', 64), purpose: 'archive'))->bytes)->toBe(17196646400);
        expect(fn () => new StartUpload('studio', (string) Str::uuid(), 'Oversized.zip', 17196646401, str_repeat('a', 64), purpose: 'archive'))->toThrow(ValidationException::class);
        foreach (['audio', 'archive'] as $purpose) {
            expect(fn () => new StartUpload('studio', (string) Str::uuid(), 'Invalid checksum', 44, str_repeat('a', 64)."\n", purpose: $purpose))->toThrow(ValidationException::class);
        }
        config()->set('music-media.archive_max_audio_bytes', 0);
        expect(fn () => ($this->archiveCommand)())->toThrow(ValidationException::class);
        expect(fn () => new StartUpload('studio', (string) Str::uuid(), 'Invalid purpose', 44, str_repeat('a', 64), purpose: 'other'))->toThrow(ValidationException::class);
    });

    it('keeps archive reservations charged until cancelled chunks are collected', function () {
        app(Gate::class)->define('music.media.quota_read', fn () => true);
        config()->set('music-media.max_reserved_bytes', strlen($this->archiveBytes) * 2 - 1);
        expect(fn () => $this->uploads->start($this->actor, ($this->archiveCommand)()))->toThrow(UploadBudgetExceeded::class);
        expect(fn () => $this->uploads->status($this->actor, 'studio', $this->archiveId))->toThrow(RecordNotFoundException::class);
        config()->set('music-media.max_reserved_bytes', strlen($this->archiveBytes) * 2);
        ($this->receiveArchive)();
        expect(app(UploadBudgets::class)->get($this->actor, 'studio')->reservedBytes)->toBe(strlen($this->archiveBytes) * 2);
        $this->uploads->cancel($this->actor, 'studio', $this->archiveId);
        expect(app(UploadBudgets::class)->get($this->actor, 'studio')->reservedBytes)->toBe(strlen($this->archiveBytes) * 2);
        app(Gate::class)->define('music.media.collect', fn () => true);
        $this->travel(86401)->seconds();
        app(QuarantineCleanup::class)->collect($this->actor,
            new CollectCancelledUploads('studio', dryRun: false));
        expect(app(UploadBudgets::class)->get($this->actor, 'studio')->reservedBytes)->toBe(0);
        $this->travelBack();
    });

    it('allows the bounded archive chunk budget without lifting the audio chunk budget', function () {
        $this->uploads->start($this->actor, ($this->archiveCommand)());
        $rows = [];
        for ($index = 1; $index <= 8191; $index++) {
            $rows[] = ['upload_id' => $this->archiveId, 'offset_bytes' => 100000 + $index, 'bytes' => 1, 'sha256' => str_repeat('a', 64), 'object_key' => 'test'];
        }
        foreach (array_chunk($rows, 512) as $batch) {
            DB::table('music_media_upload_chunks')->insert($batch);
        }
        expect($this->uploads->append($this->actor, 'studio', $this->archiveId, 0, 'P')->receivedBytes)->toBe(1);
        expect(fn () => $this->uploads->append($this->actor, 'studio', $this->archiveId, 1, 'K'))->toThrow(UploadConflict::class);
    });

    it('refuses purpose rollback while resumable archive inputs are active', function () {
        ($this->receiveArchive)();
        $migration = require __DIR__.'/../../database/migrations/2026_11_06_001446_add_archive_purpose_to_music_media_uploads.php';
        expect(fn () => $migration->down())->toThrow(LogicException::class, 'Cancel active archive inputs');
        expect(DB::table('music_media_uploads')->where('id', $this->archiveId)->value('purpose'))->toBe('archive');
        $this->uploads->cancel($this->actor, 'studio', $this->archiveId);
        $migration->down();
        expect(DB::table('music_media_uploads')->where('id', $this->archiveId)->value('status'))->toBe('cancelled');
        $migration->up();
    });

    it('adds and rolls back only purpose metadata without deleting pre-existing audio rows', function () {
        ($this->receive)();
        $this->uploads->complete($this->actor, 'studio', $this->id);
        $migration = require __DIR__.'/../../database/migrations/2026_11_06_001446_add_archive_purpose_to_music_media_uploads.php';
        $migration->down();
        expect(Schema::hasColumn('music_media_uploads', 'purpose'))->toBeFalse()->and(DB::table('music_media_uploads')->where('id', $this->id)->value('status'))->toBe('ready');
        $migration->up();
        expect(DB::table('music_media_uploads')->where('id', $this->id)->value('purpose'))->toBe('audio')
            ->and($this->uploads->get($this->actor, 'studio', $this->id)->id)->toBe($this->id);
    });
});
