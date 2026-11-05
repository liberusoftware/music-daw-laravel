<?php

use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Liberu\Foundation\Files\Contracts\MalwareScanner;
use Liberu\Music\MediaLibrary\Commands\CollectCancelledUploads;
use Liberu\Music\MediaLibrary\Commands\RetainAssets;
use Liberu\Music\MediaLibrary\Commands\SaveOfflinePin;
use Liberu\Music\MediaLibrary\Commands\StartUpload;
use Liberu\Music\MediaLibrary\Contracts\MediaAuthorizer;
use Liberu\Music\MediaLibrary\Events\QuarantineCollected;
use Liberu\Music\MediaLibrary\Exceptions\UploadConflict;
use Liberu\Music\MediaLibrary\Services\MediaRetention;
use Liberu\Music\MediaLibrary\Services\MediaUploads;
use Liberu\Music\MediaLibrary\Services\OfflinePins;
use Liberu\Music\MediaLibrary\Services\QuarantineCleanup;
use Liberu\Music\MediaLibrary\Services\UploadBudgets;

beforeEach(function () {
    $this->artisan('migrate', ['--path' => realpath(__DIR__.'/../../database/migrations'), '--realpath' => true])->assertSuccessful();
    $this->travelTo(CarbonImmutable::parse('2026-11-05T12:00:00Z'));
    config()->set('music-media.disk', 'quarantine-cleanup-test');
    $this->disk = Storage::fake('quarantine-cleanup-test');
    $this->actor = new GenericUser(['id' => 1]);
    foreach (['upload', 'read', 'retain', 'pin', 'collect'] as $operation) {
        app(Gate::class)->define('music.media.'.$operation, fn ($actor, $workspace) => $actor->id === 1 && in_array($workspace, ['studio', 'other']));
    }
    $scanner = Mockery::mock(MalwareScanner::class);
    $scanner->shouldReceive('clean')->andReturn(true);
    app()->instance(MalwareScanner::class, $scanner);
    $this->uploads = app(MediaUploads::class);
    $this->cleanup = app(QuarantineCleanup::class);
    $this->events = [];
    app(Dispatcher::class)->listen(QuarantineCollected::class, function ($event) {
        $this->events[] = $event;
    });
    $this->createUpload = function (string $workspace = 'studio', string $status = 'cancelled', int $chunks = 2): string {
        $format = pack('vvVVvv', 1, 1, 48000, 96000, 2, 16);
        $data = 'fmt '.pack('V', 16).$format.'data'.pack('V', 4).pack('v*', 0, 16384);
        $bytes = 'RIFF'.pack('V', strlen($data) + 4).'WAVE'.$data;
        $id = (string) Str::uuid();
        $this->uploads->start($this->actor, new StartUpload($workspace, $id, 'Take', strlen($bytes), hash('sha256', $bytes)));
        foreach (str_split($bytes, (int) ceil(strlen($bytes) / $chunks)) as $index => $chunk) {
            $this->uploads->append($this->actor, $workspace, $id, $index * (int) ceil(strlen($bytes) / $chunks), $chunk);
        }
        if ($status === 'ready') {
            $this->uploads->complete($this->actor, $workspace, $id);
        } elseif ($status === 'cancelled') {
            $this->uploads->cancel($this->actor, $workspace, $id);
        }

        return $id;
    };
    $this->keys = fn (string $id): array => DB::table('music_media_upload_chunks')->where('upload_id', $id)->orderBy('id')->pluck('object_key')->all();
});

it('defaults to a bounded dry run with a versioned report and no physical side effects', function () {
    $id = ($this->createUpload)();
    $keys = ($this->keys)($id);
    $this->travel(24)->hours();
    $ids = DB::table('music_media_upload_chunks')->orderBy('id')->pluck('id')->all();
    $before = DB::table('music_media_uploads')->first();
    $report = $this->cleanup->collect($this->actor, new CollectCancelledUploads('studio'));
    expect($report->jsonSerialize())->toBe(['schema_version' => 1, 'workspace_id' => 'studio', 'eligible_before' => '2026-11-05T12:00:00Z',
        'dry_run' => true, 'chunk_ids' => $ids, 'declared_bytes' => 48, 'next_chunk_id' => null]);
    expect(DB::table('music_media_uploads')->first())->toEqual($before)
        ->and(DB::table('music_media_upload_chunks')->count())->toBe(2)->and($this->events)->toBe([]);
    $this->disk->assertExists($keys);
    expect(json_encode($report))->not->toContain('quarantine/', 'blob_key', $id);
});

it('honors the UTC grace boundary and cancellation replay without extending or shortening it', function () {
    config()->set('app.timezone', 'Pacific/Auckland');
    $id = ($this->createUpload)();
    $cancelled = DB::table('music_media_uploads')->value('cancelled_at');
    $this->travel(23)->hours();
    $this->uploads->cancel($this->actor, 'studio', $id);
    expect(DB::table('music_media_uploads')->value('cancelled_at'))->toBe($cancelled);
    expect($this->cleanup->collect($this->actor, new CollectCancelledUploads('studio', dryRun: false))->chunkIds)->toBe([]);
    $this->travel(59)->minutes();
    $this->travel(59)->seconds();
    expect($this->cleanup->collect($this->actor, new CollectCancelledUploads('studio'))->chunkIds)->toBe([]);
    $this->travel(1)->seconds();
    expect($this->cleanup->collect($this->actor, new CollectCancelledUploads('studio'))->chunkIds)->toHaveCount(2);
});

it('collects in cursor ordered pages and preserves cancelled upload audit identities on replay', function () {
    $id = ($this->createUpload)();
    $keys = ($this->keys)($id);
    $this->travel(24)->hours();
    $first = $this->cleanup->collect($this->actor, new CollectCancelledUploads('studio', limit: 1, dryRun: false));
    expect($first->chunkIds)->toHaveCount(1)->and($first->nextChunkId)->toBe($first->chunkIds[0])->and($first->declaredBytes)->toBe(24);
    $this->disk->assertMissing($keys[0]);
    $this->disk->assertExists($keys[1]);
    $second = $this->cleanup->collect($this->actor, new CollectCancelledUploads('studio', limit: 1, afterChunkId: $first->nextChunkId, dryRun: false));
    expect($second->chunkIds)->toHaveCount(1)->and($second->nextChunkId)->toBeNull()->and($second->declaredBytes)->toBe(24);
    expect($this->cleanup->collect($this->actor, new CollectCancelledUploads('studio', dryRun: false))->chunkIds)->toBe([]);
    $this->disk->assertMissing($keys);
    expect($this->uploads->status($this->actor, 'studio', $id)->status)->toBe('cancelled')
        ->and(DB::table('music_media_uploads')->count())->toBe(1)->and($this->events)->toHaveCount(2)
        ->and($this->events[0]->actorId)->toBe('1')->and($this->events[0]->collection->jsonSerialize())->toBe($first->jsonSerialize());
    expect(fn () => $this->uploads->append($this->actor, 'studio', $id, 0, str_repeat('x', 24)))->toThrow(UploadConflict::class, 'cancelled');
    expect(fn () => $this->uploads->complete($this->actor, 'studio', $id))->toThrow(UploadConflict::class);
});

it('never collects uploading ready referenced duplicated or other workspace chunks', function () {
    $uploading = ($this->createUpload)('studio', 'uploading');
    $ready = ($this->createUpload)('studio', 'ready');
    $duplicate = ($this->createUpload)('studio', 'ready');
    $other = ($this->createUpload)('other');
    $cancelled = ($this->createUpload)();
    app(MediaRetention::class)->retain($this->actor, new RetainAssets('studio', 'session:1', [$ready]));
    app(OfflinePins::class)->save($this->actor, new SaveOfflinePin('studio', (string) Str::uuid(), [$duplicate], 0, 'pin'));
    $protected = array_merge(($this->keys)($uploading), ($this->keys)($ready), ($this->keys)($duplicate), ($this->keys)($other));
    $published = DB::table('music_media_uploads')->where('status', 'ready')->pluck('blob_key')->all();
    $this->travel(25)->hours();
    $report = $this->cleanup->collect($this->actor, new CollectCancelledUploads('studio', dryRun: false));
    expect($report->chunkIds)->toHaveCount(2)->and(DB::table('music_media_upload_chunks')->where('upload_id', $cancelled)->count())->toBe(0);
    $this->disk->assertExists([...$protected, ...$published]);
    expect($this->uploads->get($this->actor, 'studio', $ready)->id)->toBe($ready)
        ->and(app(MediaRetention::class)->isProtected($this->actor, 'studio', $duplicate))->toBeTrue();
});

it('rechecks workspace and per upload permissions before any file is deleted', function () {
    $first = ($this->createUpload)();
    $second = ($this->createUpload)();
    $keys = array_merge(($this->keys)($first), ($this->keys)($second));
    $this->travel(24)->hours();
    foreach ([true, false] as $dryRun) {
        expect(fn () => $this->cleanup->collect(new GenericUser(['id' => 2]), new CollectCancelledUploads('studio', dryRun: $dryRun)))->toThrow(AuthorizationException::class);
        expect(fn () => $this->cleanup->collect($this->actor, new CollectCancelledUploads('wrong', dryRun: $dryRun)))->toThrow(AuthorizationException::class);
        app(Gate::class)->define('music.media.collect', fn ($actor, $workspace, $id) => $workspace === 'studio' && $id !== $second);
        expect(fn () => $this->cleanup->collect($this->actor, new CollectCancelledUploads('studio', dryRun: $dryRun)))->toThrow(AuthorizationException::class);
    }
    $this->disk->assertExists($keys);
    expect(DB::table('music_media_upload_chunks')->count())->toBe(4)->and($this->events)->toBe([]);
});

it('rejects a corrupt object address before deleting even an earlier valid chunk', function (string $column, string|int $value) {
    $id = ($this->createUpload)();
    $keys = ($this->keys)($id);
    DB::table('music_media_upload_chunks')->orderByDesc('id')->limit(1)->update([$column => $value]);
    $this->travel(24)->hours();
    expect(fn () => $this->cleanup->collect($this->actor, new CollectCancelledUploads('studio', dryRun: false)))->toThrow(LogicException::class, 'address');
    $this->disk->assertExists($keys);
    expect(DB::table('music_media_upload_chunks')->count())->toBe(2)->and($this->events)->toBe([]);
})->with([
    ['object_key', 'music/assets/protected'], ['sha256', str_repeat('Z', 64)], ['offset_bytes', -1], ['offset_bytes', '0/../../assets'],
]);

it('retries safely after partial physical deletion without acknowledging failed storage', function () {
    $id = ($this->createUpload)();
    $keys = ($this->keys)($id);
    $this->travel(24)->hours();
    $objects = Mockery::mock(Filesystem::class);
    $objects->shouldReceive('exists')->with($keys[0])->andReturnUsing(fn () => $this->disk->exists($keys[0]));
    $objects->shouldReceive('delete')->with($keys[0])->once()->andReturnUsing(fn () => $this->disk->delete($keys[0]));
    $objects->shouldReceive('exists')->with($keys[1])->andReturn(true);
    $objects->shouldReceive('delete')->with($keys[1])->once()->andReturn(false);
    $cleanup = new QuarantineCleanup(DB::connection(), $objects, app(MediaAuthorizer::class), app(Dispatcher::class), app(UploadBudgets::class));
    expect(fn () => $cleanup->collect($this->actor, new CollectCancelledUploads('studio', dryRun: false)))->toThrow(RuntimeException::class, 'removed');
    $this->disk->assertMissing($keys[0]);
    $this->disk->assertExists($keys[1]);
    expect(DB::table('music_media_upload_chunks')->count())->toBe(2)->and($this->events)->toBe([]);
    $report = $this->cleanup->collect($this->actor, new CollectCancelledUploads('studio', dryRun: false));
    expect($report->declaredBytes)->toBe(48)->and($report->chunkIds)->toHaveCount(2)
        ->and(DB::table('music_media_upload_chunks')->count())->toBe(0)->and($this->events)->toHaveCount(1);
});

it('refuses a successful delete response while the object remains present', function () {
    $id = ($this->createUpload)();
    $keys = ($this->keys)($id);
    $this->travel(24)->hours();
    $objects = Mockery::mock(Filesystem::class);
    $objects->shouldReceive('exists')->with($keys[0])->twice()->andReturn(true);
    $objects->shouldReceive('delete')->with($keys[0])->once()->andReturn(true);
    $cleanup = new QuarantineCleanup(DB::connection(), $objects, app(MediaAuthorizer::class), app(Dispatcher::class), app(UploadBudgets::class));
    expect(fn () => $cleanup->collect($this->actor, new CollectCancelledUploads('studio', dryRun: false)))->toThrow(RuntimeException::class);
    expect(DB::table('music_media_upload_chunks')->count())->toBe(2)->and($this->events)->toBe([]);
});

it('defers events until commit and recovers removed chunks after an enclosing transaction rolls back', function () {
    ($this->createUpload)();
    $this->travel(24)->hours();
    DB::beginTransaction();
    $report = $this->cleanup->collect($this->actor, new CollectCancelledUploads('studio', dryRun: false));
    expect($this->events)->toBe([])->and(DB::table('music_media_upload_chunks')->count())->toBe(0);
    DB::rollBack();
    expect($this->events)->toBe([])->and(DB::table('music_media_upload_chunks')->count())->toBe(2);
    expect($this->cleanup->collect($this->actor, new CollectCancelledUploads('studio', dryRun: false))->jsonSerialize())->toBe($report->jsonSerialize());
    expect($this->events)->toHaveCount(1);
});

it('initializes a full grace period for legacy cancellations and reverses the schema without deleting recordings', function () {
    $id = ($this->createUpload)();
    $keys = ($this->keys)($id);
    DB::table('music_media_uploads')->where('id', $id)->update(['cancelled_at' => null, 'updated_at' => '2020-01-01 00:00:00']);
    $this->travel(48)->hours();
    $backfill = require __DIR__.'/../../database/migrations/2026_11_05_193201_initialize_music_media_cancellation_grace.php';
    $backfill->up();
    expect(DB::table('music_media_uploads')->value('cancelled_at'))->toBe('2026-11-07 12:00:00');
    expect($this->cleanup->collect($this->actor, new CollectCancelledUploads('studio'))->chunkIds)->toBe([]);
    $backfill->down();
    $schema = require __DIR__.'/../../database/migrations/2026_11_05_193200_add_cancellation_grace_to_music_media_uploads.php';
    $schema->down();
    expect(Schema::hasColumn('music_media_uploads', 'cancelled_at'))->toBeFalse();
    $this->disk->assertExists($keys);
    $schema->up();
    $backfill->up();
    expect(DB::table('music_media_upload_chunks')->count())->toBe(2);
});

it('uses a constant number of owned queries for a full dry run batch', function () {
    ($this->createUpload)();
    ($this->createUpload)();
    $this->travel(24)->hours();
    DB::enableQueryLog();
    $this->cleanup->collect($this->actor, new CollectCancelledUploads('studio'));
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    expect($queries)->toHaveCount(1)->and(strtolower($queries[0]['query']))->not->toContain('metadata', 'provenance');
});

it('validates collection boundaries before querying storage', function (string $workspace, int $grace, int $limit, int $cursor) {
    expect(fn () => new CollectCancelledUploads($workspace, $grace, $limit, $cursor))->toThrow(ValidationException::class);
})->with([
    ['', 24, 100, 0], [str_repeat('a', 129), 24, 100, 0], ['studio', 23, 100, 0], ['studio', 8761, 100, 0],
    ['studio', 24, 0, 0], ['studio', 24, 101, 0], ['studio', 24, 100, -1],
]);
