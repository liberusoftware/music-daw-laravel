<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\RecordNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Liberu\Foundation\Files\Contracts\MalwareScanner;
use Liberu\Music\MediaLibrary\Commands\RestoreAsset;
use Liberu\Music\MediaLibrary\Commands\StartUpload;
use Liberu\Music\MediaLibrary\Commands\UpdateAssetMetadata;
use Liberu\Music\MediaLibrary\Contracts\MediaAuthorizer;
use Liberu\Music\MediaLibrary\Events\AssetReady;
use Liberu\Music\MediaLibrary\Events\AssetRestored;
use Liberu\Music\MediaLibrary\Exceptions\InvalidAudio;
use Liberu\Music\MediaLibrary\Exceptions\UploadConflict;
use Liberu\Music\MediaLibrary\Services\AssetWaveforms;
use Liberu\Music\MediaLibrary\Services\MediaCatalog;
use Liberu\Music\MediaLibrary\Services\MediaUploads;
use Liberu\Music\MediaLibrary\Services\WaveInspector;

beforeEach(function () {
    $this->artisan('migrate', ['--path' => realpath(__DIR__.'/../../database/migrations'), '--realpath' => true])->assertSuccessful();
    config()->set('music-media.disk', 'restore-test');
    $this->disk = Storage::fake('restore-test');
    $this->actor = new GenericUser(['id' => 1]);
    foreach (['upload', 'read', 'restore', 'annotate', 'list'] as $operation) {
        app(Gate::class)->define('music.media.'.$operation, fn ($actor, $workspace) => $actor->id === 1 && in_array($workspace, ['studio', 'other']));
    }
    $this->scanner = Mockery::mock(MalwareScanner::class);
    $this->scanner->shouldReceive('clean')->andReturn(true)->byDefault();
    app()->instance(MalwareScanner::class, $this->scanner);
    $format = pack('vvVVvv', 1, 1, 48000, 96000, 2, 16);
    $chunks = 'fmt '.pack('V', 16).$format.'data'.pack('V', 4).pack('v*', 0, 16384);
    $this->bytes = 'RIFF'.pack('V', strlen($chunks) + 4).'WAVE'.$chunks;
    $this->uploads = app(MediaUploads::class);
    $this->receive = function (?string $bytes = null, string $workspace = 'studio'): string {
        $bytes ??= $this->bytes;
        $id = (string) Str::uuid();
        $this->uploads->start($this->actor, new StartUpload($workspace, $id, 'Take', strlen($bytes), hash('sha256', $bytes), ['license' => 'original']));
        $this->uploads->append($this->actor, $workspace, $id, 0, $bytes);

        return $id;
    };
    $this->originalId = ($this->receive)();
    $this->original = $this->uploads->complete($this->actor, 'studio', $this->originalId);
    $this->key = DB::table('music_media_uploads')->where('id', $this->originalId)->value('blob_key');
    $this->events = [];
    $this->readyEvents = [];
    app(Dispatcher::class)->listen(AssetRestored::class, function ($event) {
        $this->events[] = $event;
    });
    app(Dispatcher::class)->listen(AssetReady::class, function ($event) {
        $this->readyEvents[] = $event;
    });
});

it('restores a missing asset without changing its identity, verified metadata or labels', function () {
    app(MediaCatalog::class)->update($this->actor, new UpdateAssetMetadata('studio', $this->originalId, 'Favorite take', ['keep'], 0, 'label'));
    $this->disk->delete($this->key);
    expect(fn () => $this->uploads->get($this->actor, 'studio', $this->originalId))->toThrow(InvalidAudio::class);
    $source = ($this->receive)();
    $restored = $this->uploads->restore($this->actor, new RestoreAsset('studio', $this->originalId, $source, 'repair'));
    expect($restored)->toEqual($this->original)->and($restored->reference())->toBe($this->original->reference())
        ->and($this->disk->get($this->key))->toBe($this->bytes)->and($this->disk->getVisibility($this->key))->toBe('private')
        ->and($this->uploads->get($this->actor, 'studio', $this->originalId)->reference())->toBe($this->original->reference())
        ->and(app(AssetWaveforms::class)->build($this->actor, 'studio', $this->originalId)->audio->sha256)->toBe(hash('sha256', $this->bytes));
    expect(DB::table('music_media_annotations')->where('asset_id', $this->originalId)->value('name'))->toBe('Favorite take')
        ->and($this->events)->toHaveCount(1)->and($this->readyEvents)->toHaveCount(1)
        ->and($this->events[0]->sourceUploadId)->toBe($source)->and($this->events[0]->actorId)->toBe('1')
        ->and($this->events[0]->asset->id)->toBe($this->originalId)
        ->and(array_filter($this->disk->allFiles(), fn ($path) => str_contains($path, '.pending-')))->toBe([]);
});

it('repairs corrupt content using a separately authorized upload and replays once', function () {
    $this->disk->put($this->key, str_repeat('x', strlen($this->bytes)));
    $source = ($this->receive)();
    expect(fn () => $this->uploads->complete($this->actor, 'studio', $source))->toThrow(InvalidAudio::class, 'integrity');
    $command = new RestoreAsset('studio', $this->originalId, $source, 'repair');
    $first = $this->uploads->restore($this->actor, $command);
    expect($this->uploads->restore($this->actor, $command)->reference())->toBe($first->reference())
        ->and($this->events)->toHaveCount(1)->and($this->readyEvents)->toHaveCount(1)
        ->and(DB::table('music_media_restore_commands')->count())->toBe(1);
    $another = ($this->receive)();
    expect(fn () => $this->uploads->restore($this->actor, new RestoreAsset('studio', $this->originalId, $another, 'repair')))->toThrow(UploadConflict::class, 'command key');
    $this->disk->put($this->key, 'corrupt again');
    expect(fn () => $this->uploads->restore($this->actor, $command))->toThrow(InvalidAudio::class, 'integrity');
});

it('can use retained quarantine chunks from an already completed replacement without another ready event', function () {
    $source = ($this->receive)();
    $this->uploads->complete($this->actor, 'studio', $source);
    $this->disk->delete($this->key);
    $this->uploads->restore($this->actor, new RestoreAsset('studio', $this->originalId, $source, 'repair'));
    expect($this->events)->toHaveCount(1)->and($this->readyEvents)->toHaveCount(1)
        ->and($this->disk->get($this->key))->toBe($this->bytes);
});

it('rejects wrong audio, scope and incomplete replacement uploads before changing stored bytes', function () {
    $wrong = ($this->receive)(substr_replace($this->bytes, pack('v', 12345), 44, 2));
    expect(fn () => $this->uploads->restore($this->actor, new RestoreAsset('studio', $this->originalId, $wrong, 'wrong')))->toThrow(UploadConflict::class, 'original asset');
    $source = ($this->receive)();
    expect(fn () => $this->uploads->restore($this->actor, new RestoreAsset('other', $this->originalId, $source, 'scope')))->toThrow(RecordNotFoundException::class);
    $pending = (string) Str::uuid();
    $this->uploads->start($this->actor, new StartUpload('studio', $pending, 'Pending', strlen($this->bytes), hash('sha256', $this->bytes)));
    expect(fn () => $this->uploads->restore($this->actor, new RestoreAsset('studio', $this->originalId, $pending, 'incomplete')))->toThrow(UploadConflict::class, 'All upload bytes');
    $this->uploads->cancel($this->actor, 'studio', $source);
    expect(fn () => $this->uploads->restore($this->actor, new RestoreAsset('studio', $this->originalId, $source, 'cancelled')))->toThrow(UploadConflict::class);
    expect($this->disk->get($this->key))->toBe($this->bytes)->and($this->events)->toBe([]);
});

it('reauthorizes target reads, restore permission, replacement uploads and replay after revocation', function () {
    $source = ($this->receive)();
    $command = new RestoreAsset('studio', $this->originalId, $source, 'repair');
    expect(fn () => $this->uploads->restore(new GenericUser(['id' => 2]), $command))->toThrow(AuthorizationException::class);
    app(Gate::class)->define('music.media.read', fn () => false);
    expect(fn () => $this->uploads->restore($this->actor, $command))->toThrow(AuthorizationException::class);
    app(Gate::class)->define('music.media.read', fn () => true);
    app(Gate::class)->define('music.media.upload', fn () => false);
    expect(fn () => $this->uploads->restore($this->actor, $command))->toThrow(AuthorizationException::class);
    app(Gate::class)->define('music.media.upload', fn () => true);
    $this->uploads->restore($this->actor, $command);
    app(Gate::class)->define('music.media.restore', fn () => false);
    expect(fn () => $this->uploads->restore($this->actor, $command))->toThrow(AuthorizationException::class);
    expect($this->events)->toHaveCount(1);
});

it('leaves the existing object intact when scanning or staged publication fails', function () {
    $source = ($this->receive)();
    $command = new RestoreAsset('studio', $this->originalId, $source, 'repair');
    $this->scanner->shouldReceive('clean')->once()->andReturn(false);
    expect(fn () => $this->uploads->restore($this->actor, $command))->toThrow(InvalidAudio::class, 'quarantined');
    $clean = Mockery::mock(MalwareScanner::class);
    $clean->shouldReceive('clean')->andReturn(true);
    $store = Mockery::mock($this->disk);
    $store->shouldReceive('move')->once()->andReturn(false);
    $uploads = new MediaUploads(DB::connection(), $store, app(MediaAuthorizer::class), app(WaveInspector::class), app(Dispatcher::class), $clean);
    expect(fn () => $uploads->restore($this->actor, $command))->toThrow(RuntimeException::class, 'could not be published');
    expect($this->disk->get($this->key))->toBe($this->bytes)
        ->and(DB::table('music_media_restore_commands')->count())->toBe(0)->and($this->events)->toBe([])
        ->and(array_filter($this->disk->allFiles(), fn ($path) => str_contains($path, '.pending-')))->toBe([]);
});

it('defers restoration events to commit and safely retries after outer rollback', function () {
    $source = ($this->receive)();
    $command = new RestoreAsset('studio', $this->originalId, $source, 'repair');
    $this->disk->delete($this->key);
    DB::beginTransaction();
    $this->uploads->restore($this->actor, $command);
    expect($this->events)->toBe([])->and($this->readyEvents)->toBe([]);
    DB::rollBack();
    expect(DB::table('music_media_restore_commands')->count())->toBe(0)
        ->and($this->uploads->status($this->actor, 'studio', $source)->status)->toBe('uploading');
    $this->uploads->restore($this->actor, $command);
    expect($this->events)->toHaveCount(1)->and($this->readyEvents)->toHaveCount(1);
});

it('validates repair identities and command keys', function () {
    $other = (string) Str::uuid();
    foreach ([['', $this->originalId, $other, 'key'], ['studio', 'bad', $other, 'key'], ['studio', $this->originalId, 'bad', 'key'], ['studio', $this->originalId, $this->originalId, 'key'], ['studio', $this->originalId, strtoupper($this->originalId), 'key'], ['studio', $this->originalId, $other, '']] as [$workspace, $asset, $replacement, $key]) {
        expect(fn () => new RestoreAsset($workspace, $asset, $replacement, $key))->toThrow(ValidationException::class);
    }
});

it('does not retarget an asset whose stored location conflicts with its content address', function () {
    $source = ($this->receive)();
    DB::table('music_media_uploads')->where('id', $this->originalId)->update(['blob_key' => 'unrelated-private-object']);
    expect(fn () => $this->uploads->restore($this->actor, new RestoreAsset('studio', $this->originalId, $source, 'repair')))->toThrow(UploadConflict::class, 'content address');
    expect($this->disk->get($this->key))->toBe($this->bytes)->and($this->events)->toBe([]);
});

it('reinspects retained replacement bytes instead of trusting previously published metadata', function () {
    $source = ($this->receive)();
    $this->uploads->complete($this->actor, 'studio', $source);
    $chunk = DB::table('music_media_upload_chunks')->where('upload_id', $source)->value('object_key');
    $this->disk->put($chunk, substr_replace($this->bytes, pack('v', 12345), 44, 2));
    expect(fn () => $this->uploads->restore($this->actor, new RestoreAsset('studio', $this->originalId, $source, 'repair')))->toThrow(InvalidAudio::class, 'checksum');
    expect($this->disk->get($this->key))->toBe($this->bytes)->and($this->events)->toBe([]);
});

it('verifies staged bytes before replacing the existing content-addressed object', function () {
    $source = ($this->receive)();
    $store = Mockery::mock($this->disk);
    $store->shouldReceive('put')->once()->andReturnUsing(fn ($key, $stream, $options) => $this->disk->put($key, str_repeat('x', strlen($this->bytes)), $options));
    $store->shouldNotReceive('move');
    $uploads = new MediaUploads(DB::connection(), $store, app(MediaAuthorizer::class), app(WaveInspector::class), app(Dispatcher::class), $this->scanner);
    expect(fn () => $uploads->restore($this->actor, new RestoreAsset('studio', $this->originalId, $source, 'repair')))->toThrow(InvalidAudio::class, 'integrity');
    expect($this->disk->get($this->key))->toBe($this->bytes)->and($this->events)->toBe([])
        ->and(array_filter($this->disk->allFiles(), fn ($path) => str_contains($path, '.pending-')))->toBe([]);
});
