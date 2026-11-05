<?php

use App\Models\User;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Liberu\Foundation\Files\Contracts\MalwareScanner;
use Liberu\Music\MediaLibrary\Commands\StartUpload;
use Liberu\Music\MediaLibrary\Contracts\MediaAuthorizer;
use Liberu\Music\MediaLibrary\MusicMediaLibraryServiceProvider;
use Liberu\Music\MediaLibrary\Services\MediaUploads;
use Liberu\Music\MediaLibrary\Support\ScopedMediaAccess;

it('resolves media ownership through the configured identity provider and rechecks revoked permissions', function () {
    $this->app->register(MusicMediaLibraryServiceProvider::class);
    $this->artisan('migrate', ['--path' => base_path('modules/music-media-library/database/migrations'), '--realpath' => true])->assertSuccessful();
    config()->set('music-media.disk', 'music-access-boundary');
    Storage::fake('music-access-boundary');
    $owner = User::factory()->create();
    $outsider = User::factory()->create();
    foreach (['upload', 'read'] as $operation) {
        Gate::define('music.media.'.$operation, fn ($actor, $workspace) => $actor->is($owner) && $workspace === 'studio');
    }
    $scanner = Mockery::mock(MalwareScanner::class);
    $scanner->shouldReceive('clean')->once()->andReturn(true);
    app()->instance(MalwareScanner::class, $scanner);
    $format = pack('vvVVvv', 1, 1, 48000, 96000, 2, 16);
    $chunks = 'fmt '.pack('V', 16).$format.'data'.pack('V', 4).pack('v*', 0, 32767);
    $bytes = 'RIFF'.pack('V', strlen($chunks) + 4).'WAVE'.$chunks;
    $id = (string) Str::uuid();
    $uploads = app(MediaUploads::class);
    $uploads->start($owner, new StartUpload('studio', $id, 'Private take', strlen($bytes), hash('sha256', $bytes)));
    $uploads->append($owner, 'studio', $id, 0, $bytes);
    $access = app(ScopedMediaAccess::class);
    expect($access->authorized($owner->getKey(), $id, 'read'))->toBeFalse();
    $uploads->complete($owner, 'studio', $id);
    expect($access->authorized($owner->getKey(), $id, 'read'))->toBeTrue()
        ->and($access->authorized($outsider->getKey(), $id, 'read'))->toBeFalse()
        ->and($access->authorized(null, $id, 'read'))->toBeFalse()
        ->and($access->authorized($owner->getKey(), $id, 'write'))->toBeFalse()
        ->and($access->authorized($owner->getKey(), (string) Str::uuid(), 'read'))->toBeFalse();
    Gate::define('music.media.read', fn () => false);
    expect($access->authorized($owner->getKey(), $id, 'read'))->toBeFalse();
    $outsiderId = $outsider->getKey();
    $outsider->delete();
    expect($access->authorized($outsiderId, $id, 'read'))->toBeFalse();
});

it('fails closed when the host has no usable media identity provider', function () {
    $database = app(ConnectionInterface::class);
    $authorizer = Mockery::mock(MediaAuthorizer::class);
    $authorizer->shouldNotReceive('authorize');
    $access = new ScopedMediaAccess($database, null, $authorizer);
    expect($access->authorized(1, (string) Str::uuid(), 'read'))->toBeFalse();
    $users = Mockery::mock(UserProvider::class);
    $users->shouldReceive('retrieveById')->once()->with(123)->andReturnNull();
    $access = new ScopedMediaAccess($database, $users, $authorizer);
    expect($access->authorized(123, (string) Str::uuid(), 'read'))->toBeFalse();
});
