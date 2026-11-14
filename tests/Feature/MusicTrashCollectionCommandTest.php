<?php

use App\Models\User;
use App\Providers\MusicModuleIntegrationProvider;
use Illuminate\Database\RecordNotFoundException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Liberu\Foundation\Files\Contracts\MalwareScanner;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\Music\MediaLibrary\Commands\RecoverTrashedAsset;
use Liberu\Music\MediaLibrary\Commands\RetainAssets;
use Liberu\Music\MediaLibrary\Commands\StartUpload;
use Liberu\Music\MediaLibrary\Commands\TrashAssets;
use Liberu\Music\MediaLibrary\Exceptions\UploadConflict;
use Liberu\Music\MediaLibrary\MusicMediaLibraryServiceProvider;
use Liberu\Music\MediaLibrary\Services\AssetLifecycle;
use Liberu\Music\MediaLibrary\Services\MediaRetention;
use Liberu\Music\MediaLibrary\Services\MediaUploads;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    // Collection must commit deletion intent independently of physical deletion.
    $this->previousConnection = DB::getDefaultConnection();
    $this->previousTelescopeConnection = config('telescope.storage.database.connection');
    config()->set('database.connections.music_trash_operator', ['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true]);
    DB::setDefaultConnection('music_trash_operator');
    config()->set('telescope.storage.database.connection', 'music_trash_operator');
    Schema::clearResolvedInstance('db.schema');
    $this->freezeTime();
    $this->app->register(MusicMediaLibraryServiceProvider::class);
    (new MusicModuleIntegrationProvider($this->app))->boot();
    $this->artisan('migrate', ['--database' => 'music_trash_operator', '--force' => true])->assertSuccessful();
    config()->set('music-media.disk', 'operator-trash');
    $this->disk = Storage::fake('operator-trash');
    $this->actor = User::factory()->create();
    $this->team = Team::factory()->create(['user_id' => User::factory()->create()->id]);
    $this->team->users()->attach($this->actor, ['role' => 'editor', 'status' => 'active']);
    $this->workspace = (string) $this->team->id;
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->team->id);
    foreach (['upload', 'read', 'retain', 'trash', 'restore', 'collect'] as $operation) {
        $this->actor->givePermissionTo(Permission::findOrCreate('music.media.'.$operation, 'web'));
    }
    app()->instance(MalwareScanner::class, Mockery::mock(MalwareScanner::class)->shouldReceive('clean')->andReturnTrue()->getMock());
    $this->parameters = ['workspace' => $this->workspace, 'actor' => (string) $this->actor->id];
    $this->upload = function (int $sample = 0) {
        $format = pack('vvVVvv', 1, 1, 48000, 96000, 2, 16);
        $data = 'fmt '.pack('V', 16).$format.'data'.pack('V', 4).pack('v*', $sample, 16384);
        $bytes = 'RIFF'.pack('V', strlen($data) + 4).'WAVE'.$data;
        $id = (string) Str::uuid();
        $uploads = app(MediaUploads::class);
        $uploads->start($this->actor, new StartUpload($this->workspace, $id, 'Original.wav', strlen($bytes), hash('sha256', $bytes)));
        $uploads->append($this->actor, $this->workspace, $id, 0, $bytes);
        $uploads->complete($this->actor, $this->workspace, $id);

        return DB::table('music_media_uploads')->where('id', $id)->first();
    };
    $this->trash = fn (string $id) => app(AssetLifecycle::class)->trash($this->actor, new TrashAssets($this->workspace, [$id], 'trash:'.$id));
});

afterEach(function () {
    DB::purge('music_trash_operator');
    DB::setDefaultConnection($this->previousConnection);
    config()->set('telescope.storage.database.connection', $this->previousTelescopeConnection);
    Schema::clearResolvedInstance('db.schema');
});

it('previews bounded trash and collects without removing retained ready aliases or recent trash', function () {
    expect(DB::transactionLevel())->toBe(0);
    $shared = ($this->upload)();
    $readyAlias = ($this->upload)();
    $unique = ($this->upload)(42);
    app(MediaRetention::class)->retain($this->actor, new RetainAssets($this->workspace, 'session:original:revision:1', [$readyAlias->id]));
    expect(fn () => ($this->trash)($readyAlias->id))->toThrow(UploadConflict::class);
    ($this->trash)($shared->id);
    ($this->trash)($unique->id);
    $this->travel(25)->hours();
    $recent = ($this->upload)(43);
    ($this->trash)($recent->id);
    $eligible = [$shared->id, $unique->id];
    sort($eligible, SORT_STRING);
    expect(Artisan::call('music:media:collect-trash', $this->parameters + ['--limit' => '1']))->toBe(0);
    $preview = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($preview['dry_run'])->toBeTrue()->and($preview['asset_ids'])->toBe([$eligible[0]])
        ->and($preview['next_asset_id'])->toBe($eligible[0]);
    $this->disk->assertExists([$shared->blob_key, $unique->blob_key]);
    expect(DB::table('music_media_uploads')->where('id', $eligible[0])->value('status'))->toBe('trashed');

    expect(Artisan::call('music:media:collect-trash', $this->parameters + ['--limit' => '1', '--execute' => true]))->toBe(0);
    $first = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($first['dry_run'])->toBeFalse()->and($first['declared_bytes'])->toBe(48)->and($first['next_asset_id'])->toBe($eligible[0]);
    expect(Artisan::call('music:media:collect-trash', $this->parameters + ['--after' => $first['next_asset_id'], '--execute' => true]))->toBe(0);
    $last = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($last['asset_ids'])->toBe([$eligible[1]])->and($last['next_asset_id'])->toBeNull();
    $this->disk->assertMissing($unique->blob_key);
    $this->disk->assertExists([$readyAlias->blob_key, $recent->blob_key]);
    expect(DB::table('music_media_uploads')->whereIn('id', $eligible)->pluck('status')->all())->toBe(['collected', 'collected'])
        ->and(DB::table('music_media_upload_reservations')->whereIn('upload_id', $eligible)->sum('reserved_bytes'))->toBe(0)
        ->and(DB::table('music_media_upload_reservations')->where('upload_id', $readyAlias->id)->value('reserved_bytes'))->toBe(96)
        ->and(app(MediaRetention::class)->isProtected($this->actor, $this->workspace, $readyAlias->id))->toBeTrue()
        ->and(app(MediaUploads::class)->get($this->actor, $this->workspace, $readyAlias->id)->id)->toBe($readyAlias->id);
});

it('rechecks current collection permissions and membership without changing deletion intent on denial', function (string $revocation) {
    $asset = ($this->upload)();
    ($this->trash)($asset->id);
    $this->travel(25)->hours();
    if ($revocation === 'permission') {
        User::findOrFail($this->actor->id)->revokePermissionTo('music.media.collect');
    } else {
        $this->team->users()->updateExistingPivot($this->actor->id, ['status' => 'suspended']);
    }
    expect(Artisan::call('music:media:collect-trash', $this->parameters + ['--execute' => true]))->toBe(1);
    expect(Artisan::output())->toContain('not authorized')->not->toContain('next_asset_id');
    expect(DB::table('music_media_uploads')->where('id', $asset->id)->value('status'))->toBe('trashed');
    $this->disk->assertExists($asset->blob_key);
})->with(['permission', 'membership']);

it('leaves committed unreadable intent retryable after physical deletion but failed acknowledgement', function () {
    $asset = ($this->upload)();
    ($this->trash)($asset->id);
    $this->travel(25)->hours();
    Exceptions::fake();
    DB::unprepared("CREATE TRIGGER ignore_operator_collection BEFORE UPDATE OF status ON music_media_uploads WHEN NEW.status = 'collected' BEGIN SELECT RAISE(IGNORE); END");
    try {
        expect(Artisan::call('music:media:collect-trash', $this->parameters + ['--execute' => true]))->toBe(1);
        expect(Artisan::output())->toContain('Keep the previous cursor')->not->toContain('next_asset_id')->not->toContain($asset->blob_key);
        Exceptions::assertReported(LogicException::class);
        expect(DB::table('music_media_uploads')->where('id', $asset->id)->value('status'))->toBe('purging')
            ->and(DB::table('music_media_upload_reservations')->where('upload_id', $asset->id)->value('reserved_bytes'))->toBe(96);
        $this->disk->assertMissing($asset->blob_key);
        expect(fn () => app(MediaUploads::class)->get($this->actor, $this->workspace, $asset->id))->toThrow(RecordNotFoundException::class);
        expect(fn () => app(AssetLifecycle::class)->recover($this->actor, new RecoverTrashedAsset($this->workspace, $asset->id, 'too-late')))->toThrow(RecordNotFoundException::class);
    } finally {
        DB::unprepared('DROP TRIGGER ignore_operator_collection');
    }
    expect(Artisan::call('music:media:collect-trash', $this->parameters + ['--execute' => true]))->toBe(0);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($report['asset_ids'])->toBe([$asset->id])->and($report['next_asset_id'])->toBeNull()
        ->and(DB::table('music_media_uploads')->where('id', $asset->id)->value('status'))->toBe('collected')
        ->and(DB::table('music_media_upload_reservations')->where('upload_id', $asset->id)->value('reserved_bytes'))->toBe(0);
});

it('rejects malformed collection arguments', function (array $arguments) {
    expect(Artisan::call('music:media:collect-trash', array_replace($this->parameters, $arguments)))->toBe(2);
    expect(Artisan::output())->toContain('Invalid collection arguments');
})->with([
    'empty cursor' => [['--after' => '']],
    'invalid cursor' => [['--after' => '123']],
    'oversized batch' => [['--limit' => '101']],
    'fractional batch' => [['--limit' => '1.5']],
    'overflow actor' => [['actor' => '9223372036854775808']],
    'empty workspace' => [['workspace' => '']],
]);

it('rejects a missing actor without assuming a system identity', function () {
    expect(Artisan::call('music:media:collect-trash', array_replace($this->parameters, ['actor' => '9223372036854775807'])))->toBe(1);
    expect(Artisan::output())->toContain('actor is unavailable');
});
