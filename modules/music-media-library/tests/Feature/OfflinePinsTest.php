<?php

use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Database\QueryException;
use Illuminate\Database\RecordNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Liberu\Foundation\Files\Contracts\MalwareScanner;
use Liberu\Music\MediaLibrary\Commands\RetainAssets;
use Liberu\Music\MediaLibrary\Commands\SaveOfflinePin;
use Liberu\Music\MediaLibrary\Commands\StartUpload;
use Liberu\Music\MediaLibrary\Events\OfflinePinSaved;
use Liberu\Music\MediaLibrary\Exceptions\UploadConflict;
use Liberu\Music\MediaLibrary\Services\MediaRetention;
use Liberu\Music\MediaLibrary\Services\MediaUploads;
use Liberu\Music\MediaLibrary\Services\OfflinePins;

beforeEach(function () {
    $this->artisan('migrate', ['--path' => realpath(__DIR__.'/../../database/migrations'), '--realpath' => true])->assertSuccessful();
    $this->travelTo(CarbonImmutable::parse('2026-11-05T12:00:00Z'));
    config()->set('music-media.disk', 'offline-pins-test');
    $this->disk = Storage::fake('offline-pins-test');
    $this->actor = new GenericUser(['id' => 1]);
    foreach (['upload', 'read', 'retain', 'pin'] as $operation) {
        app(Gate::class)->define('music.media.'.$operation, fn ($actor, $workspace) => $actor->id === 1 && in_array($workspace, ['studio', 'other']));
    }
    $scanner = Mockery::mock(MalwareScanner::class);
    $scanner->shouldReceive('clean')->andReturn(true);
    app()->instance(MalwareScanner::class, $scanner);
    $this->uploads = app(MediaUploads::class);
    $this->retention = app(MediaRetention::class);
    $this->pins = app(OfflinePins::class);
    $this->pinId = (string) Str::uuid();
    $this->publish = function (string $workspace = 'studio', int $sample = 16384, bool $complete = true): string {
        $format = pack('vvVVvv', 1, 1, 48000, 96000, 2, 16);
        $chunks = 'fmt '.pack('V', 16).$format.'data'.pack('V', 4).pack('v*', 0, $sample);
        $bytes = 'RIFF'.pack('V', strlen($chunks) + 4).'WAVE'.$chunks;
        $id = (string) Str::uuid();
        $this->uploads->start($this->actor, new StartUpload($workspace, $id, 'Offline take', strlen($bytes), hash('sha256', $bytes)));
        $this->uploads->append($this->actor, $workspace, $id, 0, $bytes);
        if ($complete) {
            $this->uploads->complete($this->actor, $workspace, $id);
        }

        return $id;
    };
});

it('saves a canonical owner scoped versioned pin and returns an immutable public document', function () {
    $first = ($this->publish)();
    $second = ($this->publish)('studio', 1);
    $ids = [$first, $second];
    sort($ids, SORT_STRING);
    $command = new SaveOfflinePin('studio', $this->pinId, [$second, $first, $first], 0, 'create');
    $pin = $this->pins->save($this->actor, $command);
    expect($pin->jsonSerialize())->toBe(['schema_version' => 1, 'workspace_id' => 'studio', 'pin_id' => $this->pinId, 'asset_ids' => $ids, 'version' => 1, 'expires_at' => null])
        ->and($this->pins->get($this->actor, 'studio', $this->pinId)->jsonSerialize())->toBe($pin->jsonSerialize())
        ->and($this->retention->isProtected($this->actor, 'studio', $first))->toBeTrue()
        ->and($this->retention->isProtected($this->actor, 'studio', $second))->toBeTrue();
    expect($this->pins->save($this->actor, $command)->jsonSerialize())->toBe($pin->jsonSerialize())
        ->and(DB::table('music_media_offline_pin_commands')->count())->toBe(1);
});

it('renews leases and expires protection at the explicit UTC boundary', function () {
    $id = ($this->publish)();
    $expires = '2026-11-05T13:00:00Z';
    $first = new SaveOfflinePin('studio', $this->pinId, [$id], 0, 'lease', $expires);
    $this->pins->save($this->actor, $first);
    expect(DB::table('music_media_retention')->value('expires_at'))->toBe('2026-11-05 13:00:00')
        ->and($this->retention->isProtected($this->actor, 'studio', $id))->toBeTrue();
    $this->travelTo(CarbonImmutable::parse($expires));
    expect($this->retention->isProtected($this->actor, 'studio', $id))->toBeFalse();
    $renewed = $this->pins->save($this->actor, new SaveOfflinePin('studio', $this->pinId, [$id], 1, 'renew', '2026-11-05T14:00:00Z'));
    expect($renewed->version)->toBe(2)->and($this->retention->isProtected($this->actor, 'studio', $id))->toBeTrue();
    // An old replay returns its original result and cannot shorten the new lease.
    expect($this->pins->save($this->actor, $first)->expiresAt)->toBe($expires)
        ->and($this->pins->get($this->actor, 'studio', $this->pinId)->expiresAt)->toBe('2026-11-05T14:00:00Z');
    $this->pins->save($this->actor, new SaveOfflinePin('studio', $this->pinId, [$id], 2, 'permanent'));
    $this->travelTo(CarbonImmutable::parse('2027-11-05T12:00:00Z'));
    expect($this->retention->isProtected($this->actor, 'studio', $id))->toBeTrue()
        ->and(DB::table('music_media_retention')->value('expires_at'))->toBeNull();
});

it('replaces only its own offline asset set and does not resurrect released pins on replay', function () {
    $old = ($this->publish)();
    $new = ($this->publish)('studio', 1);
    $first = new SaveOfflinePin('studio', $this->pinId, [$old], 0, 'first');
    $this->pins->save($this->actor, $first);
    $this->pins->save($this->actor, new SaveOfflinePin('studio', $this->pinId, [$new], 1, 'replace'));
    expect($this->retention->isProtected($this->actor, 'studio', $old))->toBeFalse()
        ->and($this->retention->isProtected($this->actor, 'studio', $new))->toBeTrue();
    $release = new SaveOfflinePin('studio', $this->pinId, [], 2, 'release');
    $this->pins->save($this->actor, $release);
    $this->pins->save($this->actor, $release);
    $this->pins->save($this->actor, $first);
    expect(DB::table('music_media_retention')->count())->toBe(0)
        ->and($this->pins->get($this->actor, 'studio', $this->pinId)->assetIds)->toBe([])
        ->and($this->pins->get($this->actor, 'studio', $this->pinId)->version)->toBe(3);
});

it('preserves session, pack and other device pins when one device releases its assets', function () {
    $id = ($this->publish)();
    $this->retention->retain($this->actor, new RetainAssets('studio', 'session:1', [$id]));
    $this->retention->retain($this->actor, new RetainAssets('studio', 'pack:one', [$id], 'pack'));
    $second = (string) Str::uuid();
    $this->pins->save($this->actor, new SaveOfflinePin('studio', $second, [$id], 0, 'another-device'));
    $this->pins->save($this->actor, new SaveOfflinePin('studio', $this->pinId, [$id], 0, 'first-device', '2026-11-05T13:00:00Z'));
    $this->pins->save($this->actor, new SaveOfflinePin('studio', $this->pinId, [], 1, 'release'));
    expect(DB::table('music_media_retention')->count())->toBe(3)
        ->and($this->retention->isProtected($this->actor, 'studio', $id))->toBeTrue();
    expect(DB::table('music_media_retention')->whereIn('reference_kind', ['session-revision', 'pack'])->whereNotNull('expires_at')->exists())->toBeFalse();
});

it('rejects stale versions and conflicting command replay without changing the current pin', function () {
    $id = ($this->publish)();
    $this->pins->save($this->actor, new SaveOfflinePin('studio', $this->pinId, [$id], 0, 'first'));
    expect(fn () => $this->pins->save($this->actor, new SaveOfflinePin('studio', $this->pinId, [], 0, 'stale')))->toThrow(UploadConflict::class, 'changed');
    expect(fn () => $this->pins->save($this->actor, new SaveOfflinePin('studio', $this->pinId, [], 1, 'first')))->toThrow(UploadConflict::class, 'command key');
    expect($this->pins->get($this->actor, 'studio', $this->pinId)->version)->toBe(1)
        ->and(DB::table('music_media_retention')->count())->toBe(1);
});

it('does not disclose or mutate another actor or workspace pin even with workspace permissions', function () {
    $id = ($this->publish)();
    $this->pins->save($this->actor, new SaveOfflinePin('studio', $this->pinId, [$id], 0, 'first'));
    foreach (['pin', 'retain', 'read'] as $operation) {
        app(Gate::class)->define('music.media.'.$operation, fn () => true);
    }
    $other = new GenericUser(['id' => 2]);
    expect(fn () => $this->pins->get($other, 'studio', $this->pinId))->toThrow(RecordNotFoundException::class);
    expect(fn () => $this->pins->save($other, new SaveOfflinePin('studio', $this->pinId, [], 1, 'steal')))->toThrow(RecordNotFoundException::class);
    expect(fn () => $this->pins->get($this->actor, 'other', $this->pinId))->toThrow(RecordNotFoundException::class);
    expect(fn () => $this->pins->save($this->actor, new SaveOfflinePin('other', $this->pinId, [], 1, 'cross')))->toThrow(RecordNotFoundException::class);
    expect($this->retention->isProtected($this->actor, 'studio', $id))->toBeTrue();
});

it('reauthorizes commands, replay, current reads and every asset in a replacement', function () {
    $id = ($this->publish)();
    $command = new SaveOfflinePin('studio', $this->pinId, [$id], 0, 'first');
    expect(fn () => $this->pins->save(new GenericUser(['id' => 2]), $command))->toThrow(AuthorizationException::class);
    app(Gate::class)->define('music.media.retain', fn () => false);
    expect(fn () => $this->pins->save($this->actor, $command))->toThrow(AuthorizationException::class);
    expect(DB::table('music_media_offline_pins')->count())->toBe(0);
    app(Gate::class)->define('music.media.retain', fn () => true);
    $this->pins->save($this->actor, $command);
    app(Gate::class)->define('music.media.retain', fn () => false);
    expect(fn () => $this->pins->save($this->actor, $command))->toThrow(AuthorizationException::class);
    app(Gate::class)->define('music.media.retain', fn () => true);
    app(Gate::class)->define('music.media.read', fn () => false);
    expect(fn () => $this->pins->get($this->actor, 'studio', $this->pinId))->toThrow(AuthorizationException::class);
    expect(fn () => $this->pins->save($this->actor, $command))->toThrow(AuthorizationException::class);
    expect(fn () => $this->pins->save($this->actor, new SaveOfflinePin('studio', $this->pinId, [], 1, 'release')))->toThrow(AuthorizationException::class);
    app(Gate::class)->define('music.media.pin', fn () => false);
    expect(fn () => $this->pins->save($this->actor, $command))->toThrow(AuthorizationException::class);
    expect(fn () => $this->pins->get($this->actor, 'studio', $this->pinId))->toThrow(AuthorizationException::class);
    expect(DB::table('music_media_retention')->count())->toBe(1);
});

it('rejects missing, unfinished and wrong workspace media without publishing partial pins', function () {
    $ready = ($this->publish)();
    $pending = ($this->publish)('studio', 1, false);
    $other = ($this->publish)('other');
    foreach ([$pending, $other, (string) Str::uuid()] as $bad) {
        expect(fn () => $this->pins->save($this->actor, new SaveOfflinePin('studio', $this->pinId, [$ready, $bad], 0, 'bad')))->toThrow(RecordNotFoundException::class);
    }
    expect(DB::table('music_media_offline_pins')->count())->toBe(0)
        ->and(DB::table('music_media_retention')->count())->toBe(0);
});

it('retains known missing recordings for recovery and never deletes their bytes on release', function () {
    $id = ($this->publish)();
    $key = DB::table('music_media_uploads')->where('id', $id)->value('blob_key');
    $this->pins->save($this->actor, new SaveOfflinePin('studio', $this->pinId, [$id], 0, 'pin'));
    $this->pins->save($this->actor, new SaveOfflinePin('studio', $this->pinId, [], 1, 'release'));
    expect($this->disk->exists($key))->toBeTrue();
    $this->disk->delete($key);
    $this->pins->save($this->actor, new SaveOfflinePin('studio', $this->pinId, [$id], 2, 'recover'));
    expect($this->retention->isProtected($this->actor, 'studio', $id))->toBeTrue();
});

it('commits events exactly once and rolls manifests, pins and command logs back together', function () {
    $id = ($this->publish)();
    $events = [];
    Event::listen(OfflinePinSaved::class, function ($event) use (&$events) {
        $events[] = $event;
    });
    $command = new SaveOfflinePin('studio', $this->pinId, [$id], 0, 'first');
    DB::beginTransaction();
    $this->pins->save($this->actor, $command);
    expect($events)->toBe([]);
    DB::rollBack();
    expect($events)->toBe([])->and(DB::table('music_media_retention')->count())->toBe(0)
        ->and(DB::table('music_media_offline_pins')->count())->toBe(0)
        ->and(DB::table('music_media_offline_pin_commands')->count())->toBe(0);
    $saved = $this->pins->save($this->actor, $command);
    $this->pins->save($this->actor, $command);
    expect($events)->toHaveCount(1)->and($events[0]->actorId)->toBe('1')->and($events[0]->pin->jsonSerialize())->toBe($saved->jsonSerialize());
    DB::unprepared("CREATE TRIGGER refuse_pin_command BEFORE INSERT ON music_media_offline_pin_commands BEGIN SELECT RAISE(ABORT, 'command failed'); END");
    expect(fn () => $this->pins->save($this->actor, new SaveOfflinePin('studio', $this->pinId, [], 1, 'release')))->toThrow(QueryException::class);
    expect($this->pins->get($this->actor, 'studio', $this->pinId)->version)->toBe(1)
        ->and(DB::table('music_media_retention')->count())->toBe(1)->and($events)->toHaveCount(1);
});

it('validates identifiers, bounded lists, versions, command keys and explicit UTC lease timestamps', function () {
    $id = (string) Str::uuid();
    foreach ([
        fn () => new SaveOfflinePin('', $id, [], 0, 'key'),
        fn () => new SaveOfflinePin('studio', 'bad', [], 0, 'key'),
        fn () => new SaveOfflinePin('studio', $id, ['bad'], 0, 'key'),
        fn () => new SaveOfflinePin('studio', $id, [], -1, 'key'),
        fn () => new SaveOfflinePin('studio', $id, [], PHP_INT_MAX, 'key'),
        fn () => new SaveOfflinePin('studio', $id, [], 0, ''),
        fn () => new SaveOfflinePin('studio', $id, [], 0, 'key', '2027-01-01'),
        fn () => new SaveOfflinePin('studio', $id, [], 0, 'key', '2027-01-01T00:00:00+02:00'),
        fn () => new SaveOfflinePin('studio', $id, array_fill(0, 10001, $id), 0, 'key'),
    ] as $create) {
        expect($create)->toThrow(ValidationException::class);
    }
    expect(fn () => $this->pins->save($this->actor, new SaveOfflinePin('studio', $id, [], 0, 'expired', '2026-11-05T12:00:00Z')))->toThrow(ValidationException::class);
    expect(DB::table('music_media_offline_pins')->count())->toBe(0);
});

it('upgrades and reverses the lease schema while preserving existing permanent references', function () {
    $id = ($this->publish)();
    $this->retention->retain($this->actor, new RetainAssets('studio', 'session:1', [$id]));
    $this->artisan('migrate:rollback', ['--path' => realpath(__DIR__.'/../../database/migrations'), '--realpath' => true, '--step' => 1])->assertSuccessful();
    expect(Schema::hasColumn('music_media_retention', 'expires_at'))->toBeFalse()
        ->and(DB::table('music_media_retention')->count())->toBe(1);
    $this->artisan('migrate', ['--path' => realpath(__DIR__.'/../../database/migrations'), '--realpath' => true])->assertSuccessful();
    expect($this->retention->isProtected($this->actor, 'studio', $id))->toBeTrue()
        ->and(DB::table('music_media_retention')->value('expires_at'))->toBeNull();
});

it('keeps owned query counts bounded when saving and reading a device asset set', function () {
    $ids = [($this->publish)(), ($this->publish)(), ($this->publish)()];
    DB::enableQueryLog();
    DB::flushQueryLog();
    $this->pins->save($this->actor, new SaveOfflinePin('studio', $this->pinId, $ids, 0, 'batch'));
    $writes = DB::getQueryLog();
    DB::flushQueryLog();
    $pin = $this->pins->get($this->actor, 'studio', $this->pinId);
    $reads = DB::getQueryLog();
    DB::disableQueryLog();
    expect($writes)->toHaveCount(10)->and($reads)->toHaveCount(2)->and($pin->assetIds)->toHaveCount(3);
});
