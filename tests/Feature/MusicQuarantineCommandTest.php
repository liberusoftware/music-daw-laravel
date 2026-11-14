<?php

use App\Models\User;
use App\Providers\MusicModuleIntegrationProvider;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\Music\MediaLibrary\Commands\StartUpload;
use Liberu\Music\MediaLibrary\MusicMediaLibraryServiceProvider;
use Liberu\Music\MediaLibrary\Services\MediaUploads;
use Liberu\Music\MediaLibrary\Services\QuarantineCleanup;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->app->register(MusicMediaLibraryServiceProvider::class);
    (new MusicModuleIntegrationProvider($this->app))->boot();
    $this->artisan('migrate', ['--path' => base_path('modules/music-media-library/database/migrations'), '--realpath' => true])->assertSuccessful();
    config()->set('music-media.disk', 'operator-quarantine');
    Storage::fake('operator-quarantine');
    $this->actor = User::factory()->create();
    $this->team = Team::factory()->create(['user_id' => User::factory()->create()->id]);
    $this->team->users()->attach($this->actor, ['role' => 'editor', 'status' => 'active']);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->team->id);
    foreach (['upload', 'collect'] as $operation) {
        $this->actor->givePermissionTo(Permission::findOrCreate('music.media.'.$operation, 'web'));
    }
    $this->parameters = ['workspace' => (string) $this->team->id, 'actor' => (string) $this->actor->id];
    $this->chunk = function (bool $cancelled = true) {
        $id = (string) Str::uuid();
        $bytes = str_repeat('a', 48);
        $uploads = app(MediaUploads::class);
        $uploads->start($this->actor, new StartUpload((string) $this->team->id, $id, 'Private.wav', 48, hash('sha256', $bytes)));
        $uploads->append($this->actor, (string) $this->team->id, $id, 0, $bytes);
        if ($cancelled) {
            $uploads->cancel($this->actor, (string) $this->team->id, $id);
        }

        return DB::table('music_media_upload_chunks')->where('upload_id', $id)->first();
    };
});

it('reports bounded dry-run pages and executes only eligible cancelled chunks', function () {
    $this->freezeTime();
    $first = ($this->chunk)();
    $second = ($this->chunk)();
    $active = ($this->chunk)(false);
    $this->travel(25)->hours();
    $recent = ($this->chunk)();

    expect(Artisan::call('music:media:collect-quarantine', $this->parameters + ['--limit' => '1']))->toBe(0);
    $preview = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($preview['dry_run'])->toBeTrue()->and($preview['chunk_ids'])->toBe([$first->id])
        ->and($preview['next_chunk_id'])->toBe($first->id);
    Storage::disk('operator-quarantine')->assertExists($first->object_key);
    expect(DB::table('music_media_upload_chunks')->count())->toBe(4);

    expect(Artisan::call('music:media:collect-quarantine', $this->parameters + ['--limit' => '1', '--execute' => true]))->toBe(0);
    $collected = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($collected['dry_run'])->toBeFalse()->and($collected['declared_bytes'])->toBe(48)
        ->and($collected['next_chunk_id'])->toBe($first->id);
    Storage::disk('operator-quarantine')->assertMissing($first->object_key);
    expect(DB::table('music_media_upload_reservations')->where('upload_id', $first->upload_id)->value('reserved_bytes'))->toBe(0);

    expect(Artisan::call('music:media:collect-quarantine', $this->parameters + ['--after' => (string) $first->id, '--execute' => true]))->toBe(0);
    $last = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($last['chunk_ids'])->toBe([$second->id])->and($last['next_chunk_id'])->toBeNull();
    Storage::disk('operator-quarantine')->assertMissing($second->object_key);
    Storage::disk('operator-quarantine')->assertExists([$active->object_key, $recent->object_key]);
    expect(DB::table('music_media_upload_chunks')->count())->toBe(2);
});

it('rechecks operator membership and permissions for every invocation', function (string $revocation) {
    $chunk = ($this->chunk)();
    $this->travel(25)->hours();
    if ($revocation === 'permission') {
        User::findOrFail($this->actor->id)->revokePermissionTo('music.media.collect');
    } else {
        $this->team->users()->updateExistingPivot($this->actor->id, ['status' => 'suspended']);
    }
    expect(Artisan::call('music:media:collect-quarantine', $this->parameters + ['--execute' => true]))->toBe(1);
    expect(Artisan::output())->toContain('not authorized')->not->toContain('chunk_ids');
    Storage::disk('operator-quarantine')->assertExists($chunk->object_key);
})->with(['permission', 'membership']);

it('rejects invalid operator arguments before collection', function (array $arguments) {
    expect(Artisan::call('music:media:collect-quarantine', array_replace($this->parameters, $arguments)))->toBe(2);
    expect(Artisan::output())->toContain('Invalid collection arguments');
})->with([
    'negative cursor' => [['--after' => '-1']],
    'overflow cursor' => [['--after' => '9223372036854775808']],
    'fractional page' => [['--limit' => '1.5']],
    'oversized page' => [['--limit' => '101']],
    'short grace' => [['--grace-hours' => '23']],
    'invalid actor' => [['actor' => '1e2']],
    'empty workspace' => [['workspace' => '']],
]);

it('rejects an unavailable operator without assuming a system identity', function () {
    expect(Artisan::call('music:media:collect-quarantine', array_replace($this->parameters, ['actor' => '9223372036854775807'])))->toBe(1);
    expect(Artisan::output())->toContain('actor is unavailable');
});

it('keeps the cursor and ledger retryable when physical deletion fails', function () {
    $chunk = ($this->chunk)();
    $this->travel(25)->hours();
    Exceptions::fake();
    $objects = Mockery::mock(Filesystem::class);
    $objects->shouldReceive('exists')->once()->with($chunk->object_key)->andReturnTrue();
    $objects->shouldReceive('delete')->once()->with($chunk->object_key)->andReturnFalse();
    $this->app->when(QuarantineCleanup::class)->needs(Filesystem::class)->give(fn () => $objects);
    expect(Artisan::call('music:media:collect-quarantine', $this->parameters + ['--execute' => true]))->toBe(1);
    expect(Artisan::output())->toContain('Keep the previous cursor')->not->toContain($chunk->object_key)->not->toContain('next_chunk_id');
    Exceptions::assertReported(RuntimeException::class);
    expect(DB::table('music_media_upload_chunks')->where('id', $chunk->id)->exists())->toBeTrue();
    expect(DB::table('music_media_upload_reservations')->where('upload_id', $chunk->upload_id)->value('reserved_bytes'))->toBe(96);

    $this->app->when(QuarantineCleanup::class)->needs(Filesystem::class)->give(fn () => Storage::disk('operator-quarantine'));
    expect(Artisan::call('music:media:collect-quarantine', $this->parameters + ['--execute' => true]))->toBe(0);
    Storage::disk('operator-quarantine')->assertMissing($chunk->object_key);
    expect(DB::table('music_media_upload_reservations')->where('upload_id', $chunk->upload_id)->value('reserved_bytes'))->toBe(0);
});
