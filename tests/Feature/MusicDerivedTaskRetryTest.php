<?php

use App\Models\User;
use App\Providers\MusicModuleIntegrationProvider;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Liberu\Foundation\Files\Contracts\MalwareScanner;
use Liberu\Foundation\ImportExport\Contracts\TransferAuthorizer;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\Music\MediaLibrary\Commands\RequestArchiveExport;
use Liberu\Music\MediaLibrary\Commands\RequestWaveform;
use Liberu\Music\MediaLibrary\Commands\SaveMediaPack;
use Liberu\Music\MediaLibrary\Commands\StartUpload;
use Liberu\Music\MediaLibrary\Contracts\MediaAuthorizer;
use Liberu\Music\MediaLibrary\Exceptions\UploadConflict;
use Liberu\Music\MediaLibrary\Jobs\BuildWaveform;
use Liberu\Music\MediaLibrary\Jobs\ExportPackArchiveTask;
use Liberu\Music\MediaLibrary\MusicMediaLibraryServiceProvider;
use Liberu\Music\MediaLibrary\Services\ArchiveExports;
use Liberu\Music\MediaLibrary\Services\MediaPacks;
use Liberu\Music\MediaLibrary\Services\MediaUploads;
use Liberu\Music\MediaLibrary\Services\WaveformProcessing;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->previousConnection = DB::getDefaultConnection();
    $this->previousTransactions = app('db.transactions');
    app()->instance('db.transactions', new DatabaseTransactionsManager());
    $this->previousTelescopeConnection = config('telescope.storage.database.connection');
    config()->set('database.connections.music_derived_retry', ['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true]);
    DB::setDefaultConnection('music_derived_retry');
    config()->set('telescope.storage.database.connection', 'music_derived_retry');
    Schema::clearResolvedInstance('db.schema');
    $this->app->register(MusicMediaLibraryServiceProvider::class);
    (new MusicModuleIntegrationProvider($this->app))->boot();
    $this->artisan('migrate', ['--database' => 'music_derived_retry', '--force' => true])->assertSuccessful();
    config()->set('music-media.disk', 'host-derived-retry');
    $this->disk = Storage::fake('host-derived-retry');
    $this->actor = User::factory()->create();
    $this->team = Team::factory()->create(['user_id' => User::factory()->create()->id]);
    $this->team->users()->attach($this->actor, ['role' => 'editor', 'status' => 'active']);
    $this->workspace = (string) $this->team->id;
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->team->id);
    foreach (['upload', 'read', 'retain', 'pack_write', 'pack_read', 'pack_export', 'distribute', 'pack_distribute'] as $operation) {
        $this->actor->givePermissionTo(Permission::findOrCreate('music.media.'.$operation, 'web'));
    }
    $this->duringRead = null;
    $delegate = app(MediaAuthorizer::class);
    $callback = function () {
        $duringRead = $this->duringRead;
        $this->duringRead = null;
        if ($duringRead !== null) {
            $duringRead();
        }
    };
    app()->instance(MediaAuthorizer::class, new readonly class($delegate, $callback) implements MediaAuthorizer
    {
        public function __construct(private MediaAuthorizer $delegate, private Closure $callback) {}

        public function authorize(Authenticatable $actor, string $workspaceId, string $mediaId, string $operation): void
        {
            $this->delegate->authorize($actor, $workspaceId, $mediaId, $operation);
            if ($operation === 'read') {
                ($this->callback)();
            }
        }
    });
    app()->instance(MalwareScanner::class, Mockery::mock(MalwareScanner::class)->shouldReceive('clean')->andReturnTrue()->getMock());
    app()->instance(TransferAuthorizer::class, Mockery::mock(TransferAuthorizer::class)->shouldReceive('allowed')->with((string) $this->actor->id, 'music-media-pack', 'export')->andReturnTrue()->getMock());
    $format = pack('vvVVvv', 1, 1, 48000, 96000, 2, 16);
    $data = 'fmt '.pack('V', 16).$format.'data'.pack('V', 4).pack('v*', 0, 16384);
    $this->bytes = 'RIFF'.pack('V', strlen($data) + 4).'WAVE'.$data;
    $this->asset = (string) Str::uuid();
    $this->task = (string) Str::uuid();
    $this->pack = (string) Str::uuid();
    $rights = ['source' => 'Original', 'license' => 'CC-BY-4.0', 'attribution' => 'Artist'];
    $uploads = app(MediaUploads::class);
    $uploads->start($this->actor, new StartUpload($this->workspace, $this->asset, 'Original.wav', strlen($this->bytes), hash('sha256', $this->bytes), $rights));
    $uploads->append($this->actor, $this->workspace, $this->asset, 0, $this->bytes);
    $uploads->complete($this->actor, $this->workspace, $this->asset);
    app(MediaPacks::class)->save($this->actor, new SaveMediaPack($this->workspace, $this->pack, 'Original', [$this->asset], $rights, 0, 'source'));
    Bus::fake();
    $this->prepare = function (string $kind) {
        $this->processing = app($kind === 'waveform' ? WaveformProcessing::class : ArchiveExports::class);
        $this->command = $kind === 'waveform' ? new RequestWaveform($this->workspace, $this->task, $this->asset, 1)
            : new RequestArchiveExport($this->workspace, $this->task, $this->pack, 1);
        $this->jobClass = $kind === 'waveform' ? BuildWaveform::class : ExportPackArchiveTask::class;
        $this->taskTable = $kind === 'waveform' ? 'music_media_waveform_tasks' : 'music_media_archive_exports';
        $this->processing->request($this->actor, $this->command);

        return unserialize(serialize(Bus::dispatched($this->jobClass)->last()));
    };
});

afterEach(function () {
    DB::purge('music_derived_retry');
    DB::setDefaultConnection($this->previousConnection);
    app()->instance('db.transactions', $this->previousTransactions);
    config()->set('telescope.storage.database.connection', $this->previousTelescopeConnection);
    Schema::clearResolvedInstance('db.schema');
});

it('does not allow delayed failures or deliveries to overwrite a newer derived-task retry', function (string $kind) {
    $old = ($this->prepare)($kind);
    $old->failed(new RuntimeException('Original delivery exhausted'));
    $this->processing->request($this->actor, $this->command);
    $retry = Bus::dispatched($this->jobClass)->last();
    $old->failed(new RuntimeException('Delayed failure'));
    expect($this->processing->get($this->actor, $this->workspace, $this->task)->status)->toBe('queued');
    $old->handle($this->processing);
    expect($this->processing->get($this->actor, $this->workspace, $this->task)->status)->toBe('queued');
    $retry->handle($this->processing);
    expect($this->processing->get($this->actor, $this->workspace, $this->task)->status)->toBe('ready');
})->with(['waveform', 'export']);

it('does not acknowledge a newer waveform generation when the request is replayed during a build', function () {
    $old = ($this->prepare)('waveform');
    $retry = null;
    $this->duringRead = function () use (&$retry) {
        $this->processing->request($this->actor, $this->command);
        $retry = Bus::dispatched(BuildWaveform::class)->last();
    };
    $old->handle($this->processing);
    expect($retry)->not->toBeNull();
    expect($this->processing->get($this->actor, $this->workspace, $this->task)->status)->toBe('queued');
    $retry->handle($this->processing);
    expect($this->processing->result($this->actor, $this->workspace, $this->task)->audio->sha256)->toBe(hash('sha256', $this->bytes));
});

it('rechecks canonical permissions before processing and can retry once access is restored', function (string $kind) {
    $old = ($this->prepare)($kind);
    $permission = 'music.media.'.($kind === 'waveform' ? 'read' : 'pack_export');
    User::findOrFail($this->actor->id)->revokePermissionTo($permission);
    expect(fn () => $old->handle($this->processing))->toThrow(AuthorizationException::class);
    expect(DB::table($this->taskTable)->where('id', $this->task)->value('status'))->toBe('queued');
    $old->failed(new RuntimeException('/private/worker-secret'));
    User::findOrFail($this->actor->id)->givePermissionTo($permission);
    expect($this->processing->get($this->actor, $this->workspace, $this->task)->status)->toBe('failed');
    expect(json_encode($this->processing->get($this->actor, $this->workspace, $this->task)))->not->toContain('worker-secret')->not->toContain($old->dispatchId);
    $this->processing->request($this->actor, $this->command);
    $old->failed(new RuntimeException('Delayed permission failure'));
    Bus::dispatched($this->jobClass)->last()->handle($this->processing);
    expect($this->processing->get($this->actor, $this->workspace, $this->task)->status)->toBe('ready');
})->with(['waveform', 'export']);

it('keeps cancellation terminal without deleting the source recording or pack', function (string $kind) {
    $old = ($this->prepare)($kind);
    expect($this->processing->cancel($this->actor, $this->workspace, $this->task)->status)->toBe('cancelled');
    $old->handle($this->processing);
    $old->failed(new RuntimeException('Late cancelled delivery'));
    expect($this->processing->request($this->actor, $this->command)->status)->toBe('cancelled')
        ->and($this->processing->result($this->actor, $this->workspace, $this->task))->toBeNull()
        ->and(app(MediaUploads::class)->get($this->actor, $this->workspace, $this->asset)->id)->toBe($this->asset)
        ->and(app(MediaPacks::class)->get($this->actor, $this->workspace, $this->pack)->version)->toBe(1);
    Bus::assertDispatchedTimes($this->jobClass, 1);
})->with(['waveform', 'export']);

it('does not impersonate a deleted requester during derived processing', function (string $kind) {
    $old = ($this->prepare)($kind);
    $this->actor->delete();
    expect(fn () => $old->handle($this->processing))->toThrow(AuthorizationException::class, 'requester is unavailable');
    expect(DB::table($this->taskTable)->where('id', $this->task)->value('status'))->toBe('queued')
        ->and(DB::table('music_media_uploads')->where('id', $this->asset)->value('status'))->toBe('ready');
})->with(['waveform', 'export']);

it('restores the original dispatch after an uncommitted replay and emits no replacement delivery', function (string $kind) {
    $old = ($this->prepare)($kind);
    DB::beginTransaction();
    $this->processing->request($this->actor, $this->command);
    Bus::assertDispatchedTimes($this->jobClass, 1);
    DB::rollBack();
    expect(DB::table($this->taskTable)->where('id', $this->task)->value('dispatch_id'))->toBe($old->dispatchId);
    $old->handle($this->processing);
    expect($this->processing->get($this->actor, $this->workspace, $this->task)->status)->toBe('ready');
})->with(['waveform', 'export']);

it('does not publish a ready task when its acknowledgement fails and safely retries', function (string $kind) {
    $old = ($this->prepare)($kind);
    DB::unprepared("CREATE TRIGGER ignore_derived_ready BEFORE UPDATE OF status ON {$this->taskTable} WHEN NEW.status = 'ready' BEGIN SELECT RAISE(IGNORE); END");
    try {
        expect(fn () => $old->handle($this->processing))->toThrow($kind === 'waveform' ? UploadConflict::class : LogicException::class);
        expect($this->processing->get($this->actor, $this->workspace, $this->task)->status)->toBe('queued')
            ->and($this->processing->result($this->actor, $this->workspace, $this->task))->toBeNull();
    } finally {
        DB::unprepared('DROP TRIGGER ignore_derived_ready');
    }
    $old->failed(new RuntimeException('Unacknowledged result'));
    $this->processing->request($this->actor, $this->command);
    $old->failed(new RuntimeException('Delayed result failure'));
    Bus::dispatched($this->jobClass)->last()->handle($this->processing);
    expect($this->processing->get($this->actor, $this->workspace, $this->task)->status)->toBe('ready');
    $blob = DB::table('music_media_uploads')->where('id', $this->asset)->value('blob_key');
    expect(hash('sha256', $this->disk->get($blob)))->toBe(hash('sha256', $this->bytes));
})->with(['waveform', 'export']);

it('rebuilds an evicted waveform with a new dispatch while refusing a late completed delivery', function () {
    $old = ($this->prepare)('waveform');
    $old->handle($this->processing);
    app(Repository::class)->flush();
    expect($this->processing->result($this->actor, $this->workspace, $this->task))->toBeNull();
    $this->processing->request($this->actor, $this->command);
    $old->failed(new RuntimeException('Late completed failure'));
    $old->handle($this->processing);
    expect($this->processing->get($this->actor, $this->workspace, $this->task)->status)->toBe('queued');
    $retry = Bus::dispatched(BuildWaveform::class)->last();
    expect($retry->dispatchId)->not->toBe($old->dispatchId);
    $retry->handle($this->processing);
    expect($this->processing->result($this->actor, $this->workspace, $this->task)->peaks)->toHaveCount(2);
});
