<?php

use App\Models\User;
use App\Providers\MusicModuleIntegrationProvider;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Liberu\Foundation\Files\Contracts\MalwareScanner;
use Liberu\Foundation\ImportExport\Contracts\TransferAuthorizer;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\Music\MediaLibrary\Commands\SaveMediaPack;
use Liberu\Music\MediaLibrary\Commands\StartUpload;
use Liberu\Music\MediaLibrary\Events\ArchiveImportChanged;
use Liberu\Music\MediaLibrary\Events\AssetReady;
use Liberu\Music\MediaLibrary\Exceptions\ArchiveImportStopped;
use Liberu\Music\MediaLibrary\Exceptions\InvalidAudio;
use Liberu\Music\MediaLibrary\Jobs\ImportPackArchiveTask;
use Liberu\Music\MediaLibrary\MusicMediaLibraryServiceProvider;
use Liberu\Music\MediaLibrary\Services\ArchiveImports;
use Liberu\Music\MediaLibrary\Services\MediaPacks;
use Liberu\Music\MediaLibrary\Services\MediaUploads;
use Liberu\Music\MediaLibrary\Services\PackArchives;
use Liberu\Music\MediaLibraryApi\MusicMediaLibraryApiServiceProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->previousConnection = DB::getDefaultConnection();
    $this->previousTelescopeConnection = config('telescope.storage.database.connection');
    config()->set('database.connections.music_import_recovery', ['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true]);
    DB::setDefaultConnection('music_import_recovery');
    config()->set('telescope.storage.database.connection', 'music_import_recovery');
    Schema::clearResolvedInstance('db.schema');
    $this->freezeTime();
    $this->app->register(MusicMediaLibraryServiceProvider::class);
    $this->app->register(MusicMediaLibraryApiServiceProvider::class);
    (new MusicModuleIntegrationProvider($this->app))->boot();
    app('router')->getRoutes()->refreshNameLookups();
    $this->artisan('migrate', ['--database' => 'music_import_recovery', '--force' => true])->assertSuccessful();
    config()->set('music-media.disk', 'host-import-recovery');
    $this->disk = Storage::fake('host-import-recovery');
    $this->actor = User::factory()->create();
    $this->team = Team::factory()->create(['user_id' => User::factory()->create()->id]);
    $this->team->users()->attach($this->actor, ['role' => 'editor', 'status' => 'active']);
    $this->workspace = (string) $this->team->id;
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->team->id);
    foreach (['upload', 'read', 'retain', 'pack_write', 'pack_read', 'pack_export', 'pack_import', 'distribute', 'pack_distribute'] as $operation) {
        $this->actor->givePermissionTo(Permission::findOrCreate('music.media.'.$operation, 'web'));
    }
    $this->scanner = Mockery::mock(MalwareScanner::class);
    $this->scanner->shouldReceive('clean')->andReturnTrue()->byDefault();
    app()->instance(MalwareScanner::class, $this->scanner);
    app()->instance(TransferAuthorizer::class, Mockery::mock(TransferAuthorizer::class)->shouldReceive('allowed')->with((string) $this->actor->id, 'music-media-pack', Mockery::any())->andReturnTrue()->getMock());
    $this->uploads = app(MediaUploads::class);
    $rights = ['source' => 'Original takes', 'license' => 'CC-BY-4.0', 'attribution' => 'Artist'];
    $this->map = [];
    foreach ([1000, 2000] as $sample) {
        $format = pack('vvVVvv', 1, 1, 48000, 96000, 2, 16);
        $data = 'fmt '.pack('V', 16).$format.'data'.pack('V', 4).pack('v*', 0, $sample);
        $bytes = 'RIFF'.pack('V', strlen($data) + 4).'WAVE'.$data;
        $id = (string) Str::uuid();
        $this->uploads->start($this->actor, new StartUpload($this->workspace, $id, 'Take '.$sample, strlen($bytes), hash('sha256', $bytes), $rights));
        $this->uploads->append($this->actor, $this->workspace, $id, 0, $bytes);
        $this->uploads->complete($this->actor, $this->workspace, $id);
        $this->map[$id] = (string) Str::uuid();
    }
    $sourcePack = (string) Str::uuid();
    app(MediaPacks::class)->save($this->actor, new SaveMediaPack($this->workspace, $sourcePack, 'Originals', array_keys($this->map), $rights, 0, 'source'));
    $stream = tmpfile();
    try {
        app(PackArchives::class)->exportTo($this->actor, $this->workspace, $sourcePack, $stream);
        rewind($stream);
        $zip = stream_get_contents($stream);
    } finally {
        fclose($stream);
    }
    $this->input = (string) Str::uuid();
    $this->uploads->start($this->actor, new StartUpload($this->workspace, $this->input, 'Input.zip', strlen($zip), hash('sha256', $zip), purpose: 'archive'));
    $this->uploads->append($this->actor, $this->workspace, $this->input, 0, $zip);
    $this->task = (string) Str::uuid();
    $this->pack = (string) Str::uuid();
    $this->base = '/api/v1/music-media/workspaces/'.$this->workspace;
    $this->taskUrl = $this->base.'/archive-import-tasks/'.$this->task;
    $this->requestUrl = $this->base.'/packs/'.$this->pack.'/archive-import-tasks/'.$this->task;
    $this->payload = ['input_id' => $this->input, 'asset_map' => $this->map];
    $this->token = $this->actor->createToken('import-recovery', ['music.media.read', 'music.media.upload', 'music.media.packs.import', 'music.media.archives.import'])->plainTextToken;
    $this->withToken($this->token);
    Bus::fake();
    $this->imports = app(ArchiveImports::class);
    $this->requestImport = function () {
        $this->withHeader('Idempotency-Key', $this->task)->putJson($this->requestUrl, $this->payload)->assertAccepted();

        return Bus::dispatched(ImportPackArchiveTask::class)->last(fn ($job) => $job->taskId === $this->task);
    };
    $this->destinations = fn () => DB::table('music_media_uploads')->whereIn('id', array_values($this->map));
    $this->row = fn () => DB::table('music_media_archive_import_tasks')->where('id', $this->task)->firstOrFail();
});

afterEach(function () {
    DB::purge('music_import_recovery');
    DB::setDefaultConnection($this->previousConnection);
    config()->set('telescope.storage.database.connection', $this->previousTelescopeConnection);
    Schema::clearResolvedInstance('db.schema');
});

it('rechecks canonical worker authorization after dispatch and retries only after access is restored', function (string $revocation) {
    $job = ($this->requestImport)();
    if ($revocation === 'membership') {
        $this->team->users()->updateExistingPivot($this->actor->id, ['status' => 'suspended']);
    } else {
        User::findOrFail($this->actor->id)->revokePermissionTo('music.media.pack_import');
    }
    expect(fn () => $job->handle($this->imports))->toThrow(AuthorizationException::class);
    $this->getJson($this->taskUrl)->assertForbidden();
    expect(($this->row)()->status)->toBe('failed')->and(($this->destinations)()->count())->toBe(0)
        ->and(DB::table('music_media_packs')->where('id', $this->pack)->exists())->toBeFalse();
    if ($revocation === 'membership') {
        $this->team->users()->updateExistingPivot($this->actor->id, ['status' => 'active']);
    } else {
        User::findOrFail($this->actor->id)->givePermissionTo('music.media.pack_import');
    }
    $retry = ($this->requestImport)();
    $job->failed(new RuntimeException('Stale worker failure'));
    expect(($this->row)()->status)->toBe('queued');
    $retry->handle($this->imports);
    $this->getJson($this->taskUrl.'/result')->assertSuccessful()->assertJsonPath('data.pack_id', $this->pack)->assertJsonPath('data.version', 1);
})->with(['membership', 'permission']);

it('preserves independently verified recordings when an HTTP cancellation interrupts a running import', function () {
    $job = ($this->requestImport)();
    $cancelled = false;
    Event::listen(AssetReady::class, function ($event) use (&$cancelled) {
        if (! $cancelled && in_array($event->asset->id, $this->map, true)) {
            $cancelled = true;
            $this->deleteJson($this->taskUrl)->assertAccepted()->assertJsonPath('data.cancel_requested', true);
        }
    });
    $job->handle($this->imports);
    expect($cancelled)->toBeTrue()->and(($this->destinations)()->where('status', 'ready')->count())->toBe(1)
        ->and(DB::table('music_media_packs')->where('id', $this->pack)->exists())->toBeFalse();
    $this->getJson($this->taskUrl)->assertSuccessful()->assertJsonPath('data.status', 'cancelled');
    $this->getJson($this->taskUrl.'/result')->assertConflict();
    expect($this->uploads->status($this->actor, $this->workspace, $this->input)->status)->toBe('uploading');
    $asset = ($this->destinations)()->where('status', 'ready')->first();
    expect(hash('sha256', $this->disk->get($asset->blob_key)))->toBe($asset->sha256);
});

it('resumes the same intake and destination IDs after a scanner rejects the second recording', function () {
    $waves = 0;
    $this->scanner->shouldReceive('clean')->andReturnUsing(function (string $path) use (&$waves) {
        return substr(file_get_contents($path), 0, 4) !== 'RIFF' || ++$waves !== 2;
    });
    $job = ($this->requestImport)();
    expect(fn () => $job->handle($this->imports))->toThrow(InvalidAudio::class);
    $this->getJson($this->taskUrl)->assertSuccessful()->assertJsonPath('data.status', 'failed')
        ->assertJsonPath('data.error_code', 'archive_import_failed')->assertJsonPath('data.progress.completed_steps', 1)->assertJsonPath('data.retryable', true);
    expect(($this->destinations)()->where('status', 'ready')->count())->toBe(1)
        ->and(DB::table('music_media_packs')->where('id', $this->pack)->exists())->toBeFalse();
    $this->scanner->shouldReceive('clean')->andReturnTrue();
    ($this->requestImport)()->handle($this->imports);
    $this->getJson($this->taskUrl.'/result')->assertSuccessful()->assertJsonCount(2, 'data.assets');
    expect(($this->destinations)()->where('status', 'ready')->count())->toBe(2)
        ->and(DB::table('music_media_pack_revisions')->where('pack_id', $this->pack)->count())->toBe(1)
        ->and($this->uploads->status($this->actor, $this->workspace, $this->input)->status)->toBe('cancelled');
});

it('rolls back pack retention and source cancellation when publication acknowledgement fails', function () {
    $job = ($this->requestImport)();
    $retentionCount = DB::table('music_media_retention')->count();
    DB::unprepared("CREATE TRIGGER ignore_host_import_ready BEFORE UPDATE OF status ON music_media_archive_import_tasks WHEN NEW.status = 'ready' BEGIN SELECT RAISE(IGNORE); END");
    try {
        expect(fn () => $job->handle($this->imports))->toThrow(LogicException::class);
        expect(($this->row)()->status)->toBe('failed')->and(($this->destinations)()->where('status', 'ready')->count())->toBe(2)
            ->and(DB::table('music_media_packs')->where('id', $this->pack)->exists())->toBeFalse()
            ->and(DB::table('music_media_retention')->count())->toBe($retentionCount)
            ->and($this->uploads->status($this->actor, $this->workspace, $this->input)->status)->toBe('uploading');
        $this->getJson($this->taskUrl.'/result')->assertConflict();
    } finally {
        DB::unprepared('DROP TRIGGER ignore_host_import_ready');
    }
    ($this->requestImport)()->handle($this->imports);
    $this->getJson($this->taskUrl.'/result')->assertSuccessful();
    expect(DB::table('music_media_retention')->count())->toBe($retentionCount + 2)
        ->and(DB::table('music_media_pack_revisions')->where('pack_id', $this->pack)->count())->toBe(1);
});

it('fences an interrupted worker and its failure callback when an expired HTTP request is retried', function () {
    $job = ($this->requestImport)();
    $interrupt = true;
    Event::listen(ArchiveImportChanged::class, function ($event) use (&$interrupt) {
        if ($interrupt && $event->task->id === $this->task && $event->task->status === 'running') {
            $interrupt = false;
            throw new RuntimeException('Worker interrupted after committed claim');
        }
    });
    expect(fn () => $job->handle($this->imports))->toThrow(RuntimeException::class, 'interrupted');
    $old = ($this->row)();
    $job->handle($this->imports);
    expect(($this->row)()->attempt)->toBe(1)->and(($this->destinations)()->count())->toBe(0);
    $this->travel(76)->seconds();
    $this->getJson($this->taskUrl)->assertSuccessful()->assertJsonPath('data.error_code', 'archive_import_stalled')->assertJsonPath('data.retryable', true);
    $retry = ($this->requestImport)();
    expect($retry->dispatchId)->not->toBe($job->dispatchId);
    $job->failed(new RuntimeException('Delayed failure'));
    $job->handle($this->imports);
    expect(($this->row)()->status)->toBe('queued');
    expect(fn () => $this->imports->checkpoint($this->task, $old->lease_nonce, 0, 2, 60))->toThrow(ArchiveImportStopped::class);
    $retry->handle($this->imports);
    $job->failed(new RuntimeException('Delayed terminal failure'));
    $this->getJson($this->taskUrl.'/result')->assertSuccessful()->assertJsonCount(2, 'data.assets');
    expect(($this->row)()->attempt)->toBe(2);
});

it('cancels a queued task before dispatch without cancelling the source intake', function () {
    $job = ($this->requestImport)();
    $this->deleteJson($this->taskUrl)->assertSuccessful()->assertJsonPath('data.status', 'cancelled');
    $this->deleteJson($this->taskUrl)->assertSuccessful()->assertJsonPath('data.status', 'cancelled');
    $job->handle($this->imports);
    $this->getJson($this->taskUrl.'/result')->assertConflict();
    expect(($this->destinations)()->count())->toBe(0)
        ->and($this->uploads->status($this->actor, $this->workspace, $this->input)->status)->toBe('uploading')
        ->and(DB::table('music_media_upload_reservations')->where('upload_id', $this->input)->value('reserved_bytes'))->toBeGreaterThan(0);
});

it('never substitutes another host identity when a queued requester is deleted', function () {
    $job = ($this->requestImport)();
    $this->actor->delete();
    expect(fn () => $job->handle($this->imports))->toThrow(AuthorizationException::class, 'requester is unavailable');
    expect(($this->row)()->status)->toBe('failed')->and(($this->destinations)()->count())->toBe(0)
        ->and(DB::table('music_media_uploads')->where('id', $this->input)->value('status'))->toBe('uploading')
        ->and(DB::table('music_media_packs')->where('id', $this->pack)->exists())->toBeFalse();
});

it('keeps tasks and intake owned even for another fully authorized workspace member and denies revoked tokens', function () {
    ($this->requestImport)();
    $other = User::factory()->create();
    $this->team->users()->attach($other, ['role' => 'editor', 'status' => 'active']);
    foreach (['upload', 'read', 'retain', 'pack_write', 'pack_read', 'pack_import'] as $operation) {
        $other->givePermissionTo('music.media.'.$operation);
    }
    app()->instance(TransferAuthorizer::class, Mockery::mock(TransferAuthorizer::class)->shouldReceive('allowed')->with(Mockery::any(), 'music-media-pack', Mockery::any())->andReturnTrue()->getMock());
    $token = $other->createToken('other-member', ['music.media.read', 'music.media.upload', 'music.media.packs.import', 'music.media.archives.import'])->plainTextToken;
    app('auth')->forgetGuards();
    $this->withToken($token);
    $this->getJson($this->taskUrl)->assertNotFound();
    $this->getJson($this->taskUrl.'/result')->assertNotFound();
    $this->deleteJson($this->taskUrl)->assertNotFound();
    $foreignTask = (string) Str::uuid();
    $this->withHeader('Idempotency-Key', $foreignTask)->putJson($this->base.'/packs/'.Str::uuid().'/archive-import-tasks/'.$foreignTask, $this->payload)->assertNotFound();
    expect(($this->row)()->status)->toBe('queued')->and(($this->destinations)()->count())->toBe(0);
    $this->actor->tokens()->delete();
    app('auth')->forgetGuards();
    $this->withToken($this->token);
    $this->getJson($this->taskUrl)->assertUnauthorized();
    $this->getJson($this->taskUrl.'/result')->assertUnauthorized();
    $this->deleteJson($this->taskUrl)->assertUnauthorized();
});

it('rejects changed mappings and competing consumers while replaying the original import exactly once', function () {
    $old = ($this->requestImport)();
    $changed = $this->map;
    $changed[array_key_first($changed)] = (string) Str::uuid();
    $this->putJson($this->requestUrl, ['input_id' => $this->input, 'asset_map' => $changed])->assertConflict();
    $otherTask = (string) Str::uuid();
    $this->withHeader('Idempotency-Key', $otherTask)->putJson($this->base.'/packs/'.Str::uuid().'/archive-import-tasks/'.$otherTask, $this->payload)->assertConflict();
    Bus::assertDispatchedTimes(ImportPackArchiveTask::class, 1);
    expect(DB::table('music_media_archive_import_tasks')->count())->toBe(1)->and(($this->destinations)()->count())->toBe(0);
    $retry = ($this->requestImport)();
    $old->handle($this->imports);
    expect(($this->destinations)()->count())->toBe(0);
    $retry->handle($this->imports);
    $this->putJson($this->requestUrl, $this->payload)->assertSuccessful()->assertJsonPath('data.status', 'ready');
    $this->getJson($this->taskUrl.'/result')->assertSuccessful()->assertJsonPath('data.version', 1);
    expect(DB::table('music_media_pack_revisions')->where('pack_id', $this->pack)->count())->toBe(1);
    Bus::assertDispatchedTimes(ImportPackArchiveTask::class, 2);
});

it('rejects incomplete intake and existing destination packs before dispatching any work', function () {
    $partial = (string) Str::uuid();
    $this->uploads->start($this->actor, new StartUpload($this->workspace, $partial, 'Partial.zip', 100, hash('sha256', str_repeat('x', 100)), purpose: 'archive'));
    $this->uploads->append($this->actor, $this->workspace, $partial, 0, str_repeat('x', 48));
    $this->withHeader('Idempotency-Key', $this->task)->putJson($this->requestUrl, ['input_id' => $partial, 'asset_map' => $this->map])
        ->assertUnprocessable()->assertJsonPath('errors.0.pointer', '/input_id');
    $existingPack = DB::table('music_media_packs')->value('id');
    $this->putJson($this->base.'/packs/'.$existingPack.'/archive-import-tasks/'.$this->task, $this->payload)->assertConflict();
    Bus::assertNothingDispatched();
    expect(DB::table('music_media_archive_import_tasks')->count())->toBe(0)->and(($this->destinations)()->count())->toBe(0)
        ->and(DB::table('music_media_pack_revisions')->where('pack_id', $existingPack)->count())->toBe(1)
        ->and($this->uploads->status($this->actor, $this->workspace, $partial)->receivedBytes)->toBe(48);
});
