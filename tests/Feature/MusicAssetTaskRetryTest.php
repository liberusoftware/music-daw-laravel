<?php

use App\Models\User;
use App\Providers\MusicModuleIntegrationProvider;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Liberu\Foundation\Files\Contracts\MalwareScanner;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\Music\MediaLibrary\Commands\ProcessAsset;
use Liberu\Music\MediaLibrary\Commands\RequestTrashRecovery;
use Liberu\Music\MediaLibrary\Commands\StartUpload;
use Liberu\Music\MediaLibrary\Commands\TrashAssets;
use Liberu\Music\MediaLibrary\Jobs\ProcessAssetTask;
use Liberu\Music\MediaLibrary\Jobs\RecoverTrashTask;
use Liberu\Music\MediaLibrary\MusicMediaLibraryServiceProvider;
use Liberu\Music\MediaLibrary\Services\AssetLifecycle;
use Liberu\Music\MediaLibrary\Services\AssetProcessing;
use Liberu\Music\MediaLibrary\Services\MediaUploads;
use Liberu\Music\MediaLibrary\Services\TrashRecovery;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->previousConnection = DB::getDefaultConnection();
    $this->previousTransactions = app('db.transactions');
    app()->instance('db.transactions', new DatabaseTransactionsManager());
    $this->previousTelescopeConnection = config('telescope.storage.database.connection');
    config()->set('database.connections.music_task_retry', ['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true]);
    DB::setDefaultConnection('music_task_retry');
    config()->set('telescope.storage.database.connection', 'music_task_retry');
    Schema::clearResolvedInstance('db.schema');
    $this->app->register(MusicMediaLibraryServiceProvider::class);
    (new MusicModuleIntegrationProvider($this->app))->boot();
    $this->artisan('migrate', ['--database' => 'music_task_retry', '--force' => true])->assertSuccessful();
    config()->set('music-media.disk', 'host-task-retry');
    $this->disk = Storage::fake('host-task-retry');
    $this->actor = User::factory()->create();
    $this->team = Team::factory()->create(['user_id' => User::factory()->create()->id]);
    $this->team->users()->attach($this->actor, ['role' => 'editor', 'status' => 'active']);
    $this->workspace = (string) $this->team->id;
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->team->id);
    foreach (['upload', 'read', 'trash', 'restore'] as $operation) {
        $this->actor->givePermissionTo(Permission::findOrCreate('music.media.'.$operation, 'web'));
    }
    app()->instance(MalwareScanner::class, Mockery::mock(MalwareScanner::class)->shouldReceive('clean')->andReturnTrue()->getMock());
    $format = pack('vvVVvv', 1, 1, 48000, 96000, 2, 16);
    $data = 'fmt '.pack('V', 16).$format.'data'.pack('V', 4).pack('v*', 0, 16384);
    $this->bytes = 'RIFF'.pack('V', strlen($data) + 4).'WAVE'.$data;
    $this->asset = (string) Str::uuid();
    $this->task = (string) Str::uuid();
    $this->uploads = app(MediaUploads::class);
    $this->uploads->start($this->actor, new StartUpload($this->workspace, $this->asset, 'Original.wav', strlen($this->bytes), hash('sha256', $this->bytes)));
    $this->uploads->append($this->actor, $this->workspace, $this->asset, 0, $this->bytes);
    Bus::fake();
    $this->prepare = function (string $kind) {
        $this->source = $this->asset;
        $this->pendingStatus = $kind === 'trash' ? 'trashed' : 'uploading';
        if ($kind === 'trash') {
            $this->uploads->complete($this->actor, $this->workspace, $this->asset);
            app(AssetLifecycle::class)->trash($this->actor, new TrashAssets($this->workspace, [$this->asset], 'trash'));
            $this->processing = app(TrashRecovery::class);
            $this->command = new RequestTrashRecovery($this->workspace, $this->task, $this->asset);
            $this->jobClass = RecoverTrashTask::class;
            $this->taskTable = 'music_media_trash_recovery_tasks';
        } else {
            if ($kind === 'restoration') {
                $this->uploads->complete($this->actor, $this->workspace, $this->asset);
                $this->disk->delete(DB::table('music_media_uploads')->where('id', $this->asset)->value('blob_key'));
                $this->source = (string) Str::uuid();
                $this->uploads->start($this->actor, new StartUpload($this->workspace, $this->source, 'Replacement.wav', strlen($this->bytes), hash('sha256', $this->bytes)));
                $this->uploads->append($this->actor, $this->workspace, $this->source, 0, $this->bytes);
            }
            $this->processing = app(AssetProcessing::class);
            $this->command = new ProcessAsset($this->workspace, $this->task, $this->source, $kind === 'restoration' ? $this->asset : null);
            $this->jobClass = ProcessAssetTask::class;
            $this->taskTable = 'music_media_asset_tasks';
        }
        $this->processing->request($this->actor, $this->command);

        return unserialize(serialize(Bus::dispatched($this->jobClass)->last()));
    };
});

afterEach(function () {
    DB::purge('music_task_retry');
    DB::setDefaultConnection($this->previousConnection);
    app()->instance('db.transactions', $this->previousTransactions);
    config()->set('telescope.storage.database.connection', $this->previousTelescopeConnection);
    Schema::clearResolvedInstance('db.schema');
});

it('prevents a delayed failed delivery from overwriting a newly queued retry', function (string $kind) {
    $old = ($this->prepare)($kind);
    $old->failed(new RuntimeException('Exhausted original delivery'));
    expect($this->processing->get($this->actor, $this->workspace, $this->task)->status)->toBe('failed');
    $this->processing->request($this->actor, $this->command);
    $retry = Bus::dispatched($this->jobClass)->last();
    $old->failed(new RuntimeException('Delayed original callback'));
    expect($this->processing->get($this->actor, $this->workspace, $this->task)->status)->toBe('queued');
    $old->handle($this->processing);
    expect($this->processing->get($this->actor, $this->workspace, $this->task)->status)->toBe('queued');
    $retry->handle($this->processing);
    expect($this->processing->result($this->actor, $this->workspace, $this->task)->id)->toBe($this->asset);
})->with(['verification', 'restoration', 'trash']);

it('preserves canonical permission checks when retrying fenced jobs', function (string $kind) {
    $job = ($this->prepare)($kind);
    $permission = 'music.media.'.($kind === 'verification' ? 'upload' : 'restore');
    User::findOrFail($this->actor->id)->revokePermissionTo($permission);
    expect(fn () => $job->handle($this->processing))->toThrow(AuthorizationException::class);
    expect(DB::table($this->taskTable)->where('id', $this->task)->value('status'))->toBe('queued');
    $job->failed(new RuntimeException('Canonical permission revoked'));
    expect(DB::table($this->taskTable)->where('id', $this->task)->value('status'))->toBe('failed');
    User::findOrFail($this->actor->id)->givePermissionTo($permission);
    $this->processing->request($this->actor, $this->command);
    $retry = Bus::dispatched($this->jobClass)->last();
    $job->failed(new RuntimeException('Delayed permission failure'));
    $retry->handle($this->processing);
    expect($this->processing->result($this->actor, $this->workspace, $this->task)->id)->toBe($this->asset);
})->with(['verification', 'restoration', 'trash']);

it('keeps cancellation terminal across late deliveries without cancelling source recordings', function (string $kind) {
    $job = ($this->prepare)($kind);
    expect($this->processing->cancel($this->actor, $this->workspace, $this->task)->status)->toBe('cancelled')
        ->and($this->processing->cancel($this->actor, $this->workspace, $this->task)->status)->toBe('cancelled');
    $job->handle($this->processing);
    $job->failed(new RuntimeException('Late cancelled delivery'));
    expect($this->processing->request($this->actor, $this->command)->status)->toBe('cancelled')
        ->and($this->processing->result($this->actor, $this->workspace, $this->task))->toBeNull()
        ->and(DB::table('music_media_uploads')->where('id', $this->source)->value('status'))->toBe($this->pendingStatus);
    Bus::assertDispatchedTimes($this->jobClass, 1);
})->with(['verification', 'restoration', 'trash']);

it('rolls publication back on acknowledgement failure and safely requeues the same recording', function (string $kind) {
    $job = ($this->prepare)($kind);
    DB::unprepared("CREATE TRIGGER ignore_host_task_ready BEFORE UPDATE OF status ON {$this->taskTable} WHEN NEW.status = 'ready' BEGIN SELECT RAISE(IGNORE); END");
    try {
        expect(fn () => $job->handle($this->processing))->toThrow(LogicException::class);
        expect(DB::table($this->taskTable)->where('id', $this->task)->value('status'))->toBe('queued')
            ->and(DB::table('music_media_uploads')->where('id', $this->source)->value('status'))->toBe($this->pendingStatus);
    } finally {
        DB::unprepared('DROP TRIGGER ignore_host_task_ready');
    }
    $job->failed(new RuntimeException('Publication acknowledgement failed'));
    $this->processing->request($this->actor, $this->command);
    $job->failed(new RuntimeException('Delayed failed acknowledgement'));
    Bus::dispatched($this->jobClass)->last()->handle($this->processing);
    expect($this->processing->result($this->actor, $this->workspace, $this->task)->id)->toBe($this->asset);
    $blob = DB::table('music_media_uploads')->where('id', $this->asset)->value('blob_key');
    expect(hash('sha256', $this->disk->get($blob)))->toBe(hash('sha256', $this->bytes));
})->with(['verification', 'restoration', 'trash']);

it('does not impersonate deleted requesters or publish their pending recordings', function (string $kind) {
    $job = ($this->prepare)($kind);
    $this->actor->delete();
    expect(fn () => $job->handle($this->processing))->toThrow(AuthorizationException::class);
    expect(DB::table($this->taskTable)->where('id', $this->task)->value('status'))->toBe('queued')
        ->and(DB::table('music_media_uploads')->where('id', $this->source)->value('status'))->toBe($this->pendingStatus);
})->with(['verification', 'restoration', 'trash']);

it('rolls back replacement dispatch identities and preserves the original delivery when replay is interrupted', function (string $kind) {
    $job = ($this->prepare)($kind);
    DB::beginTransaction();
    $this->processing->request($this->actor, $this->command);
    Bus::assertDispatchedTimes($this->jobClass, 1);
    DB::rollBack();
    expect(DB::table($this->taskTable)->where('id', $this->task)->value('dispatch_id'))->toBe($job->dispatchId);
    $job->handle($this->processing);
    expect($this->processing->result($this->actor, $this->workspace, $this->task)->id)->toBe($this->asset);
})->with(['verification', 'restoration', 'trash']);
