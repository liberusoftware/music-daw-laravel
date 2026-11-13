<?php

use App\Models\User;
use App\Providers\MusicModuleIntegrationProvider;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Liberu\Foundation\Files\Contracts\MalwareScanner;
use Liberu\Foundation\ImportExport\Contracts\TransferAuthorizer;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\Music\MediaLibrary\Commands\RequestArchiveExport;
use Liberu\Music\MediaLibrary\Commands\SaveMediaPack;
use Liberu\Music\MediaLibrary\Commands\StartUpload;
use Liberu\Music\MediaLibrary\MusicMediaLibraryServiceProvider;
use Liberu\Music\MediaLibrary\Services\ArchiveExports;
use Liberu\Music\MediaLibrary\Services\MediaPacks;
use Liberu\Music\MediaLibrary\Services\MediaUploads;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->freezeTime();
    $this->app->register(MusicMediaLibraryServiceProvider::class);
    (new MusicModuleIntegrationProvider($this->app))->boot();
    $this->artisan('migrate', ['--path' => base_path('modules/music-media-library/database/migrations'), '--realpath' => true])->assertSuccessful();
    config()->set('music-media.disk', 'operator-archives');
    $this->disk = Storage::fake('operator-archives');
    $this->actor = User::factory()->create();
    $this->team = Team::factory()->create(['user_id' => User::factory()->create()->id]);
    $this->team->users()->attach($this->actor, ['role' => 'editor', 'status' => 'active']);
    $this->workspace = (string) $this->team->id;
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->team->id);
    foreach (['upload', 'read', 'retain', 'pack_write', 'pack_read', 'pack_export', 'distribute', 'pack_distribute', 'archive_cleanup', 'collect'] as $operation) {
        $this->actor->givePermissionTo(Permission::findOrCreate('music.media.'.$operation, 'web'));
    }
    app()->instance(MalwareScanner::class, Mockery::mock(MalwareScanner::class)->shouldReceive('clean')->andReturnTrue()->getMock());
    app()->instance(TransferAuthorizer::class, Mockery::mock(TransferAuthorizer::class)->shouldReceive('allowed')->with((string) $this->actor->id, 'music-media-pack', 'export')->andReturnTrue()->getMock());
    Bus::fake();
    $format = pack('vvVVvv', 1, 1, 48000, 96000, 2, 16);
    $data = 'fmt '.pack('V', 16).$format.'data'.pack('V', 4).pack('v*', 0, 16384);
    $bytes = 'RIFF'.pack('V', strlen($data) + 4).'WAVE'.$data;
    $this->asset = (string) Str::uuid();
    $this->pack = (string) Str::uuid();
    $rights = ['source' => 'Original', 'license' => 'CC-BY-4.0', 'attribution' => 'Artist'];
    $uploads = app(MediaUploads::class);
    $uploads->start($this->actor, new StartUpload($this->workspace, $this->asset, 'Original.wav', strlen($bytes), hash('sha256', $bytes), $rights));
    $uploads->append($this->actor, $this->workspace, $this->asset, 0, $bytes);
    $uploads->complete($this->actor, $this->workspace, $this->asset);
    app(MediaPacks::class)->save($this->actor, new SaveMediaPack($this->workspace, $this->pack, 'Original pack', [$this->asset], $rights, 0, 'original'));
    $this->parameters = ['workspace' => $this->workspace, 'actor' => (string) $this->actor->id, '--execute' => true];
    $this->archive = function (bool $process = true) {
        $id = (string) Str::uuid();
        $jobs = app(ArchiveExports::class);
        $jobs->request($this->actor, new RequestArchiveExport(workspaceId: $this->workspace, taskId: $id, packId: $this->pack, version: 1));
        if ($process) {
            $jobs->process($id);
        }

        return $id;
    };
    $this->key = fn (string $id) => 'music/archive-exports/'.hash('sha256', $this->workspace).'/'.hash('sha256', $id).'.zip';
});

it('removes bounded expired ZIP artifacts while preserving recordings packs retention and active work', function () {
    $first = ($this->archive)();
    $second = ($this->archive)();
    $queued = ($this->archive)(false);
    $retention = DB::table('music_media_retention')->get()->toArray();
    $reservations = DB::table('music_media_upload_reservations')->get()->toArray();
    expect(Artisan::call('music:media:cleanup-archives', $this->parameters))->toBe(0);
    expect(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['cleaned_artifacts'])->toBe(0);
    $this->travel(3601)->seconds();
    $current = ($this->archive)();
    expect(Artisan::call('music:media:cleanup-archives', $this->parameters + ['--limit' => '1']))->toBe(0);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($report)->toBe(['schema_version' => 1, 'workspace_id' => $this->workspace, 'cleaned_artifacts' => 1]);
    expect(Artisan::call('music:media:cleanup-archives', $this->parameters))->toBe(0);
    expect(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['cleaned_artifacts'])->toBe(1);
    $this->disk->assertMissing([($this->key)($first), ($this->key)($second)]);
    $this->disk->assertExists(($this->key)($current));
    expect(DB::table('music_media_archive_exports')->where('id', $queued)->value('status'))->toBe('queued')
        ->and(DB::table('music_media_retention')->get()->toArray())->toEqual($retention)
        ->and(DB::table('music_media_upload_reservations')->get()->toArray())->toEqual($reservations)
        ->and(app(MediaUploads::class)->get($this->actor, $this->workspace, $this->asset)->id)->toBe($this->asset)
        ->and(app(MediaPacks::class)->get($this->actor, $this->workspace, $this->pack)->assets[0]['reference']['id'])->toBe($this->asset);
});

it('requires deliberate execution without claiming an unsupported dry run', function () {
    $id = ($this->archive)();
    $this->travel(3601)->seconds();
    expect(Artisan::call('music:media:cleanup-archives', array_diff_key($this->parameters, ['--execute' => true])))->toBe(2);
    expect(Artisan::output())->toContain('requires --execute')->not->toContain('cleaned_artifacts');
    $this->disk->assertExists(($this->key)($id));
});

it('preserves recent cancelled staging until the full one-day grace has elapsed', function () {
    $id = ($this->archive)(false);
    app(ArchiveExports::class)->cancel($this->actor, $this->workspace, $id);
    $this->disk->put(($this->key)($id), 'interrupted private ZIP');
    $this->travel(23)->hours();
    expect(Artisan::call('music:media:cleanup-archives', $this->parameters))->toBe(0);
    expect(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['cleaned_artifacts'])->toBe(0);
    $this->disk->assertExists(($this->key)($id));
    $this->travel(2)->hours();
    expect(Artisan::call('music:media:cleanup-archives', $this->parameters))->toBe(0);
    expect(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['cleaned_artifacts'])->toBe(1);
    $this->disk->assertMissing(($this->key)($id));
});

it('does not collect another workspace even when the actor has cleanup permission in both', function () {
    $id = ($this->archive)();
    $this->travel(3601)->seconds();
    $other = Team::factory()->create(['user_id' => User::factory()->create()->id]);
    $other->users()->attach($this->actor, ['role' => 'editor', 'status' => 'active']);
    app(PermissionRegistrar::class)->setPermissionsTeamId($other->id);
    User::findOrFail($this->actor->id)->givePermissionTo('music.media.archive_cleanup');
    expect(Artisan::call('music:media:cleanup-archives', array_replace($this->parameters, ['workspace' => (string) $other->id])))->toBe(0);
    expect(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['cleaned_artifacts'])->toBe(0);
    $this->disk->assertExists(($this->key)($id));
    expect(Artisan::call('music:media:cleanup-archives', $this->parameters))->toBe(0);
    $this->disk->assertMissing(($this->key)($id));
    expect(app(PermissionRegistrar::class)->getPermissionsTeamId())->toBe($other->id);
});

it('requires current archive cleanup permission and workspace membership separately from collection', function (string $revocation) {
    $id = ($this->archive)();
    $this->travel(3601)->seconds();
    if ($revocation === 'permission') {
        User::findOrFail($this->actor->id)->revokePermissionTo('music.media.archive_cleanup');
    } else {
        $this->team->users()->updateExistingPivot($this->actor->id, ['status' => 'suspended']);
    }
    expect(Artisan::call('music:media:cleanup-archives', $this->parameters))->toBe(1);
    expect(Artisan::output())->toContain('not authorized');
    $this->disk->assertExists(($this->key)($id));
})->with(['permission', 'membership']);

it('rejects invalid cleanup arguments before deleting artifacts', function (array $arguments) {
    expect(Artisan::call('music:media:cleanup-archives', array_replace($this->parameters, $arguments)))->toBe(2);
    expect(Artisan::output())->toContain('Invalid cleanup arguments');
})->with([
    'empty workspace' => [['workspace' => '']],
    'fractional actor' => [['actor' => '1.5']],
    'overflow actor' => [['actor' => '9223372036854775808']],
    'unbounded batch' => [['--limit' => '101']],
    'zero batch' => [['--limit' => '0']],
]);

it('rejects an unavailable cleanup identity', function () {
    expect(Artisan::call('music:media:cleanup-archives', array_replace($this->parameters, ['actor' => '9223372036854775807'])))->toBe(1);
    expect(Artisan::output())->toContain('actor is unavailable');
});

it('retries missing artifacts after an acknowledgement failure without claiming success', function () {
    $id = ($this->archive)();
    $this->travel(3601)->seconds();
    Exceptions::fake();
    DB::unprepared("CREATE TRIGGER ignore_operator_archive_cleanup BEFORE UPDATE OF status ON music_media_archive_exports WHEN NEW.status = 'expired' BEGIN SELECT RAISE(IGNORE); END");
    try {
        expect(Artisan::call('music:media:cleanup-archives', $this->parameters))->toBe(1);
        expect(Artisan::output())->toContain('Retry the same batch')->not->toContain('cleaned_artifacts')->not->toContain(($this->key)($id));
        Exceptions::assertReported(LogicException::class);
        $this->disk->assertMissing(($this->key)($id));
        expect(DB::table('music_media_archive_exports')->where('id', $id)->value('status'))->toBe('ready');
    } finally {
        DB::unprepared('DROP TRIGGER ignore_operator_archive_cleanup');
    }
    expect(Artisan::call('music:media:cleanup-archives', $this->parameters))->toBe(0);
    expect(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['cleaned_artifacts'])->toBe(1);
    expect(DB::table('music_media_archive_exports')->where('id', $id)->value('status'))->toBe('expired');
});
