<?php

use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\QueryException;
use Illuminate\Database\RecordNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Liberu\Foundation\Files\Contracts\MalwareScanner;
use Liberu\Music\MediaLibrary\Commands\CollectTrashedAssets;
use Liberu\Music\MediaLibrary\Commands\ListTrashedAssets;
use Liberu\Music\MediaLibrary\Commands\RecoverTrashedAsset;
use Liberu\Music\MediaLibrary\Commands\RetainAssets;
use Liberu\Music\MediaLibrary\Commands\StartUpload;
use Liberu\Music\MediaLibrary\Commands\TrashAssets;
use Liberu\Music\MediaLibrary\Contracts\MediaAuthorizer;
use Liberu\Music\MediaLibrary\Events\AssetLifecycleChanged;
use Liberu\Music\MediaLibrary\Exceptions\InvalidAudio;
use Liberu\Music\MediaLibrary\Exceptions\UploadConflict;
use Liberu\Music\MediaLibrary\Services\AssetGarbageCollection;
use Liberu\Music\MediaLibrary\Services\AssetLifecycle;
use Liberu\Music\MediaLibrary\Services\ContentLocks;
use Liberu\Music\MediaLibrary\Services\MediaRetention;
use Liberu\Music\MediaLibrary\Services\MediaUploads;
use Liberu\Music\MediaLibrary\Services\UploadBudgets;
use Liberu\Music\MediaLibrary\Services\WaveInspector;

beforeEach(function () {
    $this->artisan('migrate', ['--path' => realpath(__DIR__.'/../../database/migrations'), '--realpath' => true])->assertSuccessful();
    $this->travelTo(CarbonImmutable::parse('2026-11-05T12:00:00Z'));
    config()->set('music-media.disk', 'asset-lifecycle');
    $this->disk = Storage::fake('asset-lifecycle');
    $this->actor = new GenericUser(['id' => 7]);
    $this->denied = null;
    foreach (['upload', 'read', 'trash', 'restore', 'collect', 'retain', 'quota_read', 'trash_list'] as $operation) {
        app(Gate::class)->define('music.media.'.$operation, fn ($actor, $workspace, $id) => $actor->id === 7 && in_array($workspace, ['studio', 'other']) && $id !== $this->denied);
    }
    app()->instance(MalwareScanner::class, Mockery::mock(MalwareScanner::class)->shouldReceive('clean')->andReturnTrue()->getMock());
    $format = pack('vvVVvv', 1, 1, 48000, 96000, 2, 16);
    $chunks = 'fmt '.pack('V', 16).$format.'data'.pack('V', 4).pack('v*', 0, 16384);
    $this->bytes = 'RIFF'.pack('V', strlen($chunks) + 4).'WAVE'.$chunks;
    $this->uploads = app(MediaUploads::class);
    $this->lifecycle = app(AssetLifecycle::class);
    $this->gc = app(AssetGarbageCollection::class);
    $this->publish = function (string $workspace = 'studio'): string {
        $id = (string) Str::uuid();
        $this->uploads->start($this->actor, new StartUpload($workspace, $id, 'Original', strlen($this->bytes), hash('sha256', $this->bytes), []));
        $this->uploads->append($this->actor, $workspace, $id, 0, $this->bytes);
        $this->uploads->complete($this->actor, $workspace, $id);

        return $id;
    };
    $this->id = ($this->publish)();
    $this->key = DB::table('music_media_uploads')->where('id', $this->id)->value('blob_key');
    $this->trash = fn (string $key = 'trash', int $grace = 24) => new TrashAssets('studio', [$this->id], $key, $grace);
    $this->collect = fn (bool $dry = false, int $limit = 100, ?string $cursor = null) => new CollectTrashedAssets('studio', $limit, $cursor, $dry);
    $this->events = [];
    app(Dispatcher::class)->listen(AssetLifecycleChanged::class, function ($event) {
        $this->events[] = $event;
    });
    $this->makeGc = fn ($store) => new AssetGarbageCollection(DB::connection(), $store, app(MediaAuthorizer::class), app(ContentLocks::class), app(UploadBudgets::class), app(Dispatcher::class));
});

it('trashes and recovers verified audio with replay-safe receipts and after-commit events', function () {
    DB::beginTransaction();
    $change = $this->lifecycle->trash($this->actor, ($this->trash)());
    expect($this->events)->toBe([]);
    DB::commit();
    expect($change->purgeAfter)->toBe('2026-11-06T12:00:00Z')->and($this->events)->toHaveCount(1)
        ->and($this->lifecycle->trash($this->actor, ($this->trash)()))->toEqual($change);
    expect(fn () => $this->uploads->cancel($this->actor, 'studio', $this->id))->toThrow(UploadConflict::class)
        ->and(fn () => $this->uploads->get($this->actor, 'studio', $this->id))->toThrow(RecordNotFoundException::class)
        ->and(fn () => $this->uploads->complete($this->actor, 'studio', $this->id))->toThrow(UploadConflict::class);
    expect(fn () => $this->uploads->startBatch($this->actor, [new StartUpload('studio', $this->id, 'Original', strlen($this->bytes), hash('sha256', $this->bytes), [])]))->toThrow(UploadConflict::class);
    $recovered = $this->lifecycle->recover($this->actor, new RecoverTrashedAsset('studio', $this->id, 'undo'));
    expect($recovered->status)->toBe('ready')->and($recovered->purgeAfter)->toBeNull()
        ->and($this->lifecycle->recover($this->actor, new RecoverTrashedAsset('studio', $this->id, 'undo')))->toEqual($recovered)
        ->and($this->uploads->get($this->actor, 'studio', $this->id)->id)->toBe($this->id)->and($this->events)->toHaveCount(2);
    expect(json_decode(json_encode($change), true))->toBe(['schema_version' => 1, 'workspace_id' => 'studio', 'asset_ids' => [$this->id], 'status' => 'trashed', 'changed_at' => '2026-11-05T12:00:00Z', 'purge_after' => '2026-11-06T12:00:00Z']);
    // Replaying the old trash receipt after recovery does not trash again.
    $this->lifecycle->trash($this->actor, ($this->trash)());
    expect($this->uploads->get($this->actor, 'studio', $this->id)->id)->toBe($this->id);
});

it('refuses changed command keys unauthorized workspaces and partial batches atomically', function () {
    $other = ($this->publish)();
    $this->lifecycle->trash($this->actor, ($this->trash)());
    expect(fn () => $this->lifecycle->trash($this->actor, new TrashAssets('studio', [$other], 'trash')))->toThrow(UploadConflict::class)
        ->and(fn () => $this->lifecycle->recover($this->actor, new RecoverTrashedAsset('studio', $this->id, 'trash')))->toThrow(UploadConflict::class)
        ->and(fn () => $this->lifecycle->trash($this->actor, new TrashAssets('other', [$other], 'wrong')))->toThrow(RecordNotFoundException::class)
        ->and(fn () => $this->lifecycle->trash($this->actor, new TrashAssets('studio', [$other, (string) Str::uuid()], 'partial')))->toThrow(RecordNotFoundException::class);
    expect($this->uploads->get($this->actor, 'studio', $other)->id)->toBe($other);
    $this->denied = $other;
    expect(fn () => $this->lifecycle->trash($this->actor, new TrashAssets('studio', [$other], 'denied')))->toThrow(AuthorizationException::class);
    $this->denied = $this->id;
    expect(fn () => $this->lifecycle->recover($this->actor, new RecoverTrashedAsset('studio', $this->id, 'denied')))->toThrow(AuthorizationException::class);
});

it('protects permanent session and pack references and active offline pins', function (string $kind, ?string $expires) {
    app(MediaRetention::class)->retain($this->actor, new RetainAssets('studio', 'reference', [$this->id], $kind));
    DB::table('music_media_retention')->where('asset_id', $this->id)->update(['expires_at' => $expires]);
    expect(fn () => $this->lifecycle->trash($this->actor, ($this->trash)()))->toThrow(UploadConflict::class);
    expect($this->uploads->get($this->actor, 'studio', $this->id)->id)->toBe($this->id);
})->with([['session-revision', null], ['pack', null], ['offline-pin', '2026-11-05 12:00:01']]);

it('collects after the UTC grace boundary and releases reservations exactly once', function () {
    app(MediaRetention::class)->retain($this->actor, new RetainAssets('studio', 'expired', [$this->id], 'offline-pin'));
    DB::table('music_media_retention')->where('asset_id', $this->id)->update(['expires_at' => '2026-11-05 12:00:00']);
    $this->lifecycle->trash($this->actor, ($this->trash)());
    expect($this->gc->collect($this->actor, ($this->collect)())->assetIds)->toBe([]);
    $this->travel(24)->hours();
    $dry = $this->gc->collect($this->actor, ($this->collect)(true));
    expect($dry->assetIds)->toBe([$this->id])->and($this->disk->exists($this->key))->toBeTrue()
        ->and(app(UploadBudgets::class)->get($this->actor, 'studio')->reservedBytes)->toBe(96);
    $report = $this->gc->collect($this->actor, ($this->collect)());
    expect($report->assetIds)->toBe([$this->id])->and($report->declaredBytes)->toBe(48)->and($this->disk->exists($this->key))->toBeFalse()
        ->and(DB::table('music_media_upload_chunks')->count())->toBe(0)->and(app(UploadBudgets::class)->get($this->actor, 'studio')->reservedBytes)->toBe(0)
        ->and(DB::table('music_media_uploads')->where('id', $this->id)->value('status'))->toBe('collected');
    expect($this->gc->collect($this->actor, ($this->collect)())->assetIds)->toBe([])
        ->and(fn () => $this->lifecycle->recover($this->actor, new RecoverTrashedAsset('studio', $this->id, 'late')))->toThrow(RecordNotFoundException::class);
    expect(json_encode($report))->not->toContain('blob_key', 'reference_id')->and($report->jsonSerialize()['schema_version'])->toBe(1);
});

it('preserves live and grace-period shared aliases and isolated workspace objects', function () {
    $alias = ($this->publish)();
    $foreign = ($this->publish)('other');
    $this->lifecycle->trash($this->actor, ($this->trash)());
    $this->travel(24)->hours();
    $this->gc->collect($this->actor, ($this->collect)());
    expect($this->disk->exists($this->key))->toBeTrue()->and($this->uploads->get($this->actor, 'studio', $alias)->id)->toBe($alias);
    $third = ($this->publish)();
    $this->lifecycle->trash($this->actor, new TrashAssets('studio', [$alias], 'alias', 48));
    $this->lifecycle->trash($this->actor, new TrashAssets('studio', [$third], 'third'));
    $this->travel(24)->hours();
    $this->gc->collect($this->actor, ($this->collect)());
    expect($this->disk->exists($this->key))->toBeTrue();
    $this->lifecycle->recover($this->actor, new RecoverTrashedAsset('studio', $alias, 'recover-alias'));
    $this->lifecycle->trash($this->actor, new TrashAssets('studio', [$alias], 'last'));
    $this->travel(24)->hours();
    $this->gc->collect($this->actor, ($this->collect)());
    expect($this->disk->exists($this->key))->toBeFalse()->and($this->uploads->get($this->actor, 'other', $foreign)->id)->toBe($foreign);
});

it('paginates dry runs and real collection without skipping remaining candidates', function () {
    $ids = [$this->id, ($this->publish)(), ($this->publish)()];
    sort($ids);
    $this->lifecycle->trash($this->actor, new TrashAssets('studio', $ids, 'batch'));
    $this->travel(24)->hours();
    $one = $this->gc->collect($this->actor, ($this->collect)(true, 1));
    $two = $this->gc->collect($this->actor, ($this->collect)(true, 1, $one->nextAssetId));
    expect($one->assetIds)->toBe([$ids[0]])->and($two->assetIds)->toBe([$ids[1]])->and($two->nextAssetId)->toBe($ids[1]);
    $this->gc->collect($this->actor, ($this->collect)(false, 2));
    expect($this->gc->collect($this->actor, ($this->collect)())->assetIds)->toBe([$ids[2]]);
});

it('retains committed unreadable intent after file failure and resumes missing-object acknowledgements', function () {
    $this->lifecycle->trash($this->actor, ($this->trash)());
    $this->travel(24)->hours();
    $store = Mockery::mock(Filesystem::class);
    $store->shouldReceive('exists')->andReturnUsing(fn ($key) => $this->disk->exists($key));
    $store->shouldReceive('delete')->andReturnUsing(function ($key) {
        if ($key === $this->key) {
            return false;
        }

        return $this->disk->delete($key);
    });
    expect(fn () => ($this->makeGc)($store)->collect($this->actor, ($this->collect)()))->toThrow(RuntimeException::class);
    expect(DB::table('music_media_uploads')->where('id', $this->id)->value('status'))->toBe('purging')
        ->and(app(UploadBudgets::class)->get($this->actor, 'studio')->reservedBytes)->toBe(96)
        ->and(fn () => $this->lifecycle->recover($this->actor, new RecoverTrashedAsset('studio', $this->id, 'too-late')))->toThrow(RecordNotFoundException::class);
    expect($this->gc->collect($this->actor, ($this->collect)())->assetIds)->toBe([$this->id])
        ->and(app(UploadBudgets::class)->get($this->actor, 'studio')->reservedBytes)->toBe(0);
});

it('retries safely after SQL failure following physical deletion', function () {
    $this->lifecycle->trash($this->actor, ($this->trash)());
    $this->travel(24)->hours();
    DB::unprepared("CREATE TRIGGER reject_collection BEFORE UPDATE OF status ON music_media_uploads WHEN NEW.status = 'collected' BEGIN SELECT RAISE(ABORT, 'collection failure'); END");
    expect(fn () => $this->gc->collect($this->actor, ($this->collect)()))->toThrow(QueryException::class);
    expect($this->disk->exists($this->key))->toBeFalse()->and(DB::table('music_media_uploads')->where('id', $this->id)->value('status'))->toBe('purging');
    DB::unprepared('DROP TRIGGER reject_collection');
    $this->gc->collect($this->actor, ($this->collect)());
    expect(app(UploadBudgets::class)->get($this->actor, 'studio')->reservedBytes)->toBe(0);
});

it('forbids caller-owned collection transactions and validates content-lock connection ownership', function () {
    DB::beginTransaction();
    expect(fn () => $this->gc->collect($this->actor, ($this->collect)()))->toThrow(LogicException::class);
    DB::rollBack();
    expect(fn () => app(ContentLocks::class)->acquire('studio', hash('sha256', $this->bytes), DB::connection()))->toThrow(LogicException::class);
    config()->set('database.connections.other', config('database.connections.'.config('database.default')));
    DB::beginTransaction();
    expect(fn () => app(ContentLocks::class)->acquire('studio', hash('sha256', $this->bytes), DB::connection('other')))->toThrow(LogicException::class);
    DB::rollBack();
});

it('validates lifecycle commands', function () {
    foreach ([[], [(string) Str::uuid(), (string) Str::uuid(), 'bad'], array_fill(0, 101, $this->id), [$this->id, $this->id]] as $ids) {
        expect(fn () => new TrashAssets('studio', $ids, 'key'))->toThrow(ValidationException::class);
    }
    expect(fn () => new TrashAssets('studio', [$this->id], '', 23))->toThrow(ValidationException::class)
        ->and(fn () => new RecoverTrashedAsset('', 'bad', ''))->toThrow(ValidationException::class)
        ->and(fn () => new CollectTrashedAssets('', 101, 'bad'))->toThrow(ValidationException::class);
});

it('lists trash with one bounded query and no private storage or retention details', function () {
    $alias = ($this->publish)();
    $this->lifecycle->trash($this->actor, new TrashAssets('studio', [$this->id, $alias], 'batch'));
    DB::enableQueryLog();
    DB::flushQueryLog();
    $page = $this->lifecycle->list($this->actor, new ListTrashedAssets('studio', 1));
    expect(DB::getQueryLog())->toHaveCount(1)->and($page->items)->toHaveCount(1)->and($page->nextAssetId)->not->toBeNull()
        ->and(json_encode($page))->not->toContain('blob_key', 'actor_id', 'reference_id')->and($page->items[0]->trashedAt)->toBe('2026-11-05T12:00:00Z');
    DB::disableQueryLog();
    $last = $this->lifecycle->list($this->actor, new ListTrashedAssets('studio', 1, $page->nextAssetId));
    expect($last->items)->toHaveCount(1)->and($last->nextAssetId)->toBeNull();
    $this->denied = $last->items[0]->id;
    expect(fn () => $this->lifecycle->list($this->actor, new ListTrashedAssets('studio')))->toThrow(AuthorizationException::class);
    expect(fn () => new ListTrashedAssets('', 0, 'bad'))->toThrow(ValidationException::class);
});

it('refuses missing unreadable or altered trash audio during recovery', function () {
    $this->lifecycle->trash($this->actor, ($this->trash)());
    $this->disk->delete($this->key);
    expect(fn () => $this->lifecycle->recover($this->actor, new RecoverTrashedAsset('studio', $this->id, 'missing')))->toThrow(InvalidAudio::class);
    $changed = substr_replace($this->bytes, pack('v', 1), -2);
    $this->disk->put($this->key, $changed);
    expect(fn () => $this->lifecycle->recover($this->actor, new RecoverTrashedAsset('studio', $this->id, 'altered')))->toThrow(InvalidAudio::class, 'differs');
    $store = Mockery::mock(Filesystem::class);
    $store->shouldReceive('exists')->andReturnTrue();
    $store->shouldReceive('readStream')->andReturnFalse();
    $lifecycle = new AssetLifecycle(DB::connection(), $store, app(MediaAuthorizer::class), app(ContentLocks::class), app(Dispatcher::class), app(WaveInspector::class));
    expect(fn () => $lifecycle->recover($this->actor, new RecoverTrashedAsset('studio', $this->id, 'unreadable')))->toThrow(InvalidAudio::class, 'could not be opened');
});

it('rolls back trash and its event when saving the command receipt fails', function () {
    DB::unprepared('CREATE TRIGGER reject_receipt BEFORE UPDATE OF result ON music_media_lifecycle_commands BEGIN SELECT RAISE(IGNORE); END');
    expect(fn () => $this->lifecycle->trash($this->actor, ($this->trash)()))->toThrow(LogicException::class);
    expect($this->events)->toBe([])->and(DB::table('music_media_lifecycle_commands')->count())->toBe(0)
        ->and($this->uploads->get($this->actor, 'studio', $this->id)->id)->toBe($this->id);
});

it('refuses collection when authorization or an unexpected active reference prevents it', function () {
    $this->lifecycle->trash($this->actor, ($this->trash)());
    $this->travel(24)->hours();
    $this->denied = $this->id;
    expect(fn () => $this->gc->collect($this->actor, ($this->collect)()))->toThrow(AuthorizationException::class);
    $this->denied = null;
    DB::table('music_media_retention')->insert(['asset_id' => $this->id, 'workspace_id' => 'studio', 'reference_hash' => str_repeat('a', 64), 'reference_kind' => 'pack', 'reference_id' => 'unexpected', 'actor_id' => '7', 'created_at' => now('UTC'), 'expires_at' => null]);
    expect(fn () => $this->gc->collect($this->actor, ($this->collect)()))->toThrow(UploadConflict::class);
    expect($this->disk->exists($this->key))->toBeTrue()->and(DB::table('music_media_uploads')->where('id', $this->id)->value('status'))->toBe('trashed');
});

it('never follows forged content or chunk ledger addresses', function (string $target) {
    $this->lifecycle->trash($this->actor, ($this->trash)());
    $this->travel(24)->hours();
    if ($target === 'blob') {
        DB::table('music_media_uploads')->where('id', $this->id)->update(['blob_key' => 'unrelated/private-file']);
    } else {
        DB::table('music_media_upload_chunks')->where('upload_id', $this->id)->update(['object_key' => 'unrelated/private-file']);
    }
    $this->disk->put('unrelated/private-file', 'keep');
    expect(fn () => $this->gc->collect($this->actor, ($this->collect)()))->toThrow(LogicException::class);
    expect($this->disk->get('unrelated/private-file'))->toBe('keep')->and($this->disk->exists($this->key))->toBeTrue();
})->with(['blob', 'chunk']);

it('rejects an oversized chunk ledger before deleting any files', function () {
    $this->lifecycle->trash($this->actor, ($this->trash)());
    $this->travel(24)->hours();
    $rows = [];
    for ($i = 1; $i <= 4096; $i++) {
        $rows[] = ['upload_id' => $this->id, 'offset_bytes' => $i, 'bytes' => 1, 'sha256' => str_repeat('a', 64), 'object_key' => 'invalid'];
    }
    foreach (array_chunk($rows, 100) as $batch) {
        DB::table('music_media_upload_chunks')->insert($batch);
    }
    expect(fn () => $this->gc->collect($this->actor, ($this->collect)()))->toThrow(LogicException::class, 'bound');
    expect($this->disk->exists($this->key))->toBeTrue();
});

it('acknowledges a duplicate collection delivery after another collector finished its intent', function () {
    $this->lifecycle->trash($this->actor, ($this->trash)());
    $this->travel(24)->hours();
    app(Dispatcher::class)->listen(AssetLifecycleChanged::class, function ($event) {
        if ($event->change->status === 'purging') {
            $this->gc->collect($this->actor, ($this->collect)());
        }
    });
    $this->gc->collect($this->actor, ($this->collect)());
    expect(app(UploadBudgets::class)->get($this->actor, 'studio')->reservedBytes)->toBe(0)
        ->and(array_values(array_filter($this->events, fn ($event) => $event->change->status === 'collected')))->toHaveCount(1);
});

it('preserves a new publication arriving after deletion intent and can republish collected content', function () {
    $this->lifecycle->trash($this->actor, ($this->trash)());
    $this->travel(24)->hours();
    $alias = null;
    app(Dispatcher::class)->listen(AssetLifecycleChanged::class, function ($event) use (&$alias) {
        if ($event->change->status === 'purging' && $alias === null) {
            $alias = ($this->publish)();
        }
    });
    $this->gc->collect($this->actor, ($this->collect)());
    expect($this->disk->exists($this->key))->toBeTrue()->and($this->uploads->get($this->actor, 'studio', $alias)->id)->toBe($alias);
    $this->lifecycle->trash($this->actor, new TrashAssets('studio', [$alias], 'last'));
    $this->travel(24)->hours();
    $this->gc->collect($this->actor, ($this->collect)());
    expect($this->disk->exists($this->key))->toBeFalse();
    $fresh = ($this->publish)();
    expect($this->uploads->get($this->actor, 'studio', $fresh)->id)->toBe($fresh)->and($this->disk->exists($this->key))->toBeTrue();
});

it('rolls the lifecycle schema down and up without reviving or deleting recordings', function () {
    $this->lifecycle->trash($this->actor, ($this->trash)());
    $migration = require glob(__DIR__.'/../../database/migrations/*create_music_media_asset_lifecycle.php')[0];
    $migration->down();
    expect($this->disk->exists($this->key))->toBeTrue()->and(DB::table('music_media_uploads')->where('id', $this->id)->value('status'))->toBe('trashed');
    $migration->up();
    expect($this->disk->exists($this->key))->toBeTrue()->and(DB::table('music_media_uploads')->where('id', $this->id)->value('status'))->toBe('trashed');
});

it('fails safely when the database ignores a lifecycle transition', function (string $status) {
    if ($status !== 'trashed') {
        $this->lifecycle->trash($this->actor, ($this->trash)());
    }
    $this->travel(24)->hours();
    DB::unprepared("CREATE TRIGGER ignore_transition BEFORE UPDATE OF status ON music_media_uploads WHEN NEW.status = '$status' BEGIN SELECT RAISE(IGNORE); END");
    $operation = match ($status) {
        'trashed' => fn () => $this->lifecycle->trash($this->actor, ($this->trash)()),
        'ready' => fn () => $this->lifecycle->recover($this->actor, new RecoverTrashedAsset('studio', $this->id, 'recover')),
        default => fn () => $this->gc->collect($this->actor, ($this->collect)()),
    };
    expect($operation)->toThrow(LogicException::class);
    expect(app(UploadBudgets::class)->get($this->actor, 'studio')->reservedBytes)->toBe(96)
        ->and($this->disk->exists($this->key))->toBe($status !== 'collected');
    if ($status === 'collected') {
        DB::unprepared('DROP TRIGGER ignore_transition');
        $this->gc->collect($this->actor, ($this->collect)());
        expect(app(UploadBudgets::class)->get($this->actor, 'studio')->reservedBytes)->toBe(0);
    }
})->with(['trashed', 'ready', 'purging', 'collected']);
