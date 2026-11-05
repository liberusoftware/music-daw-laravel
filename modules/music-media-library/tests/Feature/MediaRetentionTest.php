<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\RecordNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Liberu\Foundation\Files\Contracts\MalwareScanner;
use Liberu\Music\MediaLibrary\Commands\RetainAssets;
use Liberu\Music\MediaLibrary\Commands\StartUpload;
use Liberu\Music\MediaLibrary\Exceptions\InvalidAudio;
use Liberu\Music\MediaLibrary\Services\MediaRetention;
use Liberu\Music\MediaLibrary\Services\MediaUploads;

beforeEach(function () {
    $this->artisan('migrate', ['--path' => realpath(__DIR__.'/../../database/migrations'), '--realpath' => true])->assertSuccessful();
    config()->set('music-media.disk', 'retention-test');
    $this->disk = Storage::fake('retention-test');
    $this->actor = new GenericUser(['id' => 1]);
    foreach (['upload', 'read', 'retain'] as $operation) {
        app(Gate::class)->define('music.media.'.$operation, fn ($actor, $workspace) => $actor->id === 1 && in_array($workspace, ['studio', 'other']));
    }
    $scanner = Mockery::mock(MalwareScanner::class);
    $scanner->shouldReceive('clean')->andReturn(true);
    app()->instance(MalwareScanner::class, $scanner);
    $format = pack('vvVVvv', 1, 1, 48000, 96000, 2, 16);
    $chunks = 'fmt '.pack('V', 16).$format.'data'.pack('V', 4).pack('v*', 0, 16384);
    $this->bytes = 'RIFF'.pack('V', strlen($chunks) + 4).'WAVE'.$chunks;
    $this->uploads = app(MediaUploads::class);
    $this->retention = app(MediaRetention::class);
    $this->publish = function (string $workspace = 'studio', bool $complete = true): string {
        $id = (string) Str::uuid();
        $this->uploads->start($this->actor, new StartUpload($workspace, $id, 'Take', strlen($this->bytes), hash('sha256', $this->bytes)));
        $this->uploads->append($this->actor, $workspace, $id, 0, $this->bytes);
        if ($complete) {
            $this->uploads->complete($this->actor, $workspace, $id);
        }

        return $id;
    };
});

it('retains ready assets idempotently for independent revision and offline references', function () {
    $id = ($this->publish)();
    $command = new RetainAssets('studio', 'project:1', [$id, $id]);
    expect($this->retention->isProtected($this->actor, 'studio', $id))->toBeFalse();
    expect($this->retention->retain($this->actor, $command))->toBe(1)
        ->and($this->retention->retain($this->actor, $command))->toBe(1)
        ->and(DB::table('music_media_retention')->count())->toBe(1)
        ->and($this->retention->isProtected($this->actor, 'studio', $id))->toBeTrue();
    $this->retention->retain($this->actor, new RetainAssets('studio', 'project:2', [$id]));
    $this->retention->retain($this->actor, new RetainAssets('studio', 'device:one', [$id], 'offline-pin'));
    $this->retention->retain($this->actor, new RetainAssets('studio', 'pack:one', [$id], 'pack'));
    expect(DB::table('music_media_retention')->count())->toBe(4)
        ->and(DB::table('music_media_retention')->where('reference_kind', 'offline-pin')->value('actor_id'))->toBe('1');
    expect($this->retention->retain($this->actor, new RetainAssets('studio', 'empty', [])))->toBe(0);
});

it('protects shared content without crossing workspace boundaries or exposing reference identities', function () {
    $first = ($this->publish)();
    $duplicate = ($this->publish)();
    $other = ($this->publish)('other');
    $this->retention->retain($this->actor, new RetainAssets('studio', 'private-session:1', [$first]));
    expect($this->retention->isProtected($this->actor, 'studio', $duplicate))->toBeTrue()
        ->and($this->retention->isProtected($this->actor, 'other', $other))->toBeFalse();
    expect(fn () => $this->retention->isProtected($this->actor, 'other', $first))->toThrow(RecordNotFoundException::class);
});

it('rejects receiving, missing and wrong workspace assets without partial pins', function () {
    $ready = ($this->publish)();
    $pending = ($this->publish)('studio', false);
    foreach ([[$ready, $pending], [$ready, (string) Str::uuid()]] as $ids) {
        expect(fn () => $this->retention->retain($this->actor, new RetainAssets('studio', 'revision', $ids)))->toThrow(RecordNotFoundException::class);
    }
    expect(fn () => $this->retention->retain($this->actor, new RetainAssets('other', 'wrong', [$ready])))->toThrow(RecordNotFoundException::class);
    expect(DB::table('music_media_retention')->count())->toBe(0);
});

it('checks retention and per-asset read permission on each retry', function () {
    $id = ($this->publish)();
    $command = new RetainAssets('studio', 'revision:1', [$id]);
    expect(fn () => $this->retention->retain(new GenericUser(['id' => 2]), $command))->toThrow(AuthorizationException::class);
    app(Gate::class)->define('music.media.read', fn () => false);
    expect(fn () => $this->retention->retain($this->actor, $command))->toThrow(AuthorizationException::class);
    expect(DB::table('music_media_retention')->count())->toBe(0);
    app(Gate::class)->define('music.media.read', fn () => true);
    $this->retention->retain($this->actor, $command);
    app(Gate::class)->define('music.media.retain', fn () => false);
    expect(fn () => $this->retention->retain($this->actor, $command))->toThrow(AuthorizationException::class);
    app(Gate::class)->define('music.media.read', fn () => false);
    expect(fn () => $this->retention->isProtected($this->actor, 'studio', $id))->toThrow(AuthorizationException::class);
});

it('requires the same active database transaction and rolls pins back with its caller', function () {
    $id = ($this->publish)();
    $command = new RetainAssets('studio', 'revision:1', [$id]);
    expect(fn () => $this->retention->retainInTransaction($this->actor, $command, DB::connection()))->toThrow(LogicException::class);
    $other = Mockery::mock(ConnectionInterface::class);
    expect(fn () => $this->retention->retainInTransaction($this->actor, $command, $other))->toThrow(LogicException::class);
    DB::beginTransaction();
    $this->retention->retainInTransaction($this->actor, $command, DB::connection());
    expect(DB::table('music_media_retention')->count())->toBe(1);
    DB::rollBack();
    expect(DB::table('music_media_retention')->count())->toBe(0);
});

it('can retain an existing missing recording for recovery without publishing new media', function () {
    $id = ($this->publish)();
    $this->disk->delete(DB::table('music_media_uploads')->where('id', $id)->value('blob_key'));
    $this->retention->retain($this->actor, new RetainAssets('studio', 'recover-me', [$id], 'offline-pin'));
    expect($this->retention->isProtected($this->actor, 'studio', $id))->toBeTrue();
    expect(fn () => $this->uploads->get($this->actor, 'studio', $id))->toThrow(InvalidAudio::class);
});

it('retains a batch with two owned database statements instead of one insert per asset', function () {
    $ids = [($this->publish)(), ($this->publish)(), ($this->publish)()];
    DB::beginTransaction();
    DB::enableQueryLog();
    DB::flushQueryLog();
    $count = $this->retention->retainInTransaction($this->actor, new RetainAssets('studio', 'revision:1', $ids), DB::connection());
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    DB::rollBack();
    expect($count)->toBe(3)->and($queries)->toHaveCount(2);
});

it('validates retention identities, reference kinds and bounded asset identifiers', function () {
    foreach ([fn () => new RetainAssets('', 'reference', []), fn () => new RetainAssets('studio', '', []), fn () => new RetainAssets('studio', 'reference', ['bad']), fn () => new RetainAssets('studio', 'reference', [], 'unknown'), fn () => new RetainAssets('studio', 'reference', array_fill(0, 10001, (string) Str::uuid()))] as $create) {
        expect($create)->toThrow(ValidationException::class);
    }
});

it('bounds insert parameters when a revision retains hundreds of assets', function () {
    $id = ($this->publish)();
    $source = (array) DB::table('music_media_uploads')->where('id', $id)->first();
    $rows = [];
    $ids = [];
    for ($index = 0; $index < 501; $index++) {
        $row = $source;
        $row['id'] = (string) Str::uuid();
        $rows[] = $row;
        $ids[] = $row['id'];
    }
    foreach (array_chunk($rows, 100) as $batch) {
        DB::table('music_media_uploads')->insert($batch);
    }
    DB::beginTransaction();
    DB::enableQueryLog();
    DB::flushQueryLog();
    $count = $this->retention->retainInTransaction($this->actor, new RetainAssets('studio', 'large:1', $ids), DB::connection());
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    expect($count)->toBe(501)->and($queries)->toHaveCount(3)
        ->and(DB::table('music_media_retention')->count())->toBe(501);
    DB::rollBack();
});
