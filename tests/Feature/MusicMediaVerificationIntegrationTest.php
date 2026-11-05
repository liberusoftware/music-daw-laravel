<?php

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\RecordNotFoundException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Liberu\Foundation\Files\Contracts\MalwareScanner;
use Liberu\Music\MediaLibrary\Commands\StartUpload;
use Liberu\Music\MediaLibrary\Exceptions\InvalidAudio;
use Liberu\Music\MediaLibrary\MusicMediaLibraryServiceProvider;
use Liberu\Music\MediaLibrary\Services\AssetWaveforms;
use Liberu\Music\MediaLibrary\Services\MediaUploads;
use Liberu\Music\MediaLibrary\Services\WaveInspector;

it('resolves the packaged audio verifier and derives metadata from uploaded bytes', function () {
    $format = pack('vvVVvv', 1, 1, 48000, 96000, 2, 16);
    $audio = pack('v*', 0, 32767, 65535, 16384);
    $chunks = 'fmt '.pack('V', strlen($format)).$format.'data'.pack('V', strlen($audio)).$audio;
    $bytes = 'RIFF'.pack('V', strlen($chunks) + 4).'WAVE'.$chunks;
    $stream = fopen('php://temp', 'w+b');
    fwrite($stream, $bytes);
    try {
        $metadata = app(WaveInspector::class)->inspect($stream);
        expect($metadata->sha256)->toBe(hash('sha256', $bytes))
            ->and($metadata->sampleRate)->toBe(48000)->and($metadata->channels)->toBe(1)
            ->and($metadata->frames)->toBe(4);
        expect(fn () => app(WaveInspector::class)->inspect($stream, 8))->toThrow(InvalidAudio::class);
    } finally {
        fclose($stream);
    }
});

it('composes private receiving and verification without making unscanned audio available', function () {
    $this->app->register(MusicMediaLibraryServiceProvider::class);
    $this->artisan('migrate', ['--path' => base_path('modules/music-media-library/database/migrations'), '--realpath' => true])->assertSuccessful();
    config()->set('music-media.disk', 'music-host-test');
    Storage::fake('music-host-test');
    $actor = User::factory()->create();
    Gate::define('music.media.upload', fn ($user, $workspace) => $user->getKey() === $actor->getKey() && $workspace === 'studio');
    Gate::define('music.media.read', fn ($user, $workspace) => $user->getKey() === $actor->getKey() && $workspace === 'studio');
    $format = pack('vvVVvv', 1, 1, 48000, 96000, 2, 16);
    $chunks = 'fmt '.pack('V', 16).$format.'data'.pack('V', 4).pack('v*', 0, 32767);
    $bytes = 'RIFF'.pack('V', strlen($chunks) + 4).'WAVE'.$chunks;
    $id = (string) Str::uuid();
    $uploads = app(MediaUploads::class);
    $uploads->start($actor, new StartUpload('studio', $id, 'Host recording', strlen($bytes), hash('sha256', $bytes)));
    $uploads->append($actor, 'studio', $id, 0, $bytes);
    expect(fn () => $uploads->get($actor, 'studio', $id))->toThrow(RecordNotFoundException::class);
    expect(fn () => $uploads->complete($actor, 'studio', $id))->toThrow(InvalidAudio::class, 'quarantined');
    $scanner = Mockery::mock(MalwareScanner::class);
    $scanner->shouldReceive('clean')->once()->andReturnUsing(fn ($path) => is_file($path) && hash_file('sha256', $path) === hash('sha256', $bytes));
    app()->instance(MalwareScanner::class, $scanner);
    $verified = app(MediaUploads::class)->complete($actor, 'studio', $id);
    expect($verified->reference())->toBe(['id' => $id, 'sha256' => hash('sha256', $bytes), 'sample_rate' => 48000, 'channels' => 1, 'frames' => 2]);
    $waveform = app(AssetWaveforms::class)->build($actor, 'studio', $id, 1);
    expect($waveform->peaks)->toHaveCount(2)
        ->and($waveform->peaks[0]['channels'][0]['maximum'])->toBe(0.0)
        ->and($waveform->peaks[1]['channels'][0]['maximum'])->toBe(32767 / 32768);
    Gate::define('music.media.read', fn () => false);
    expect(fn () => app(AssetWaveforms::class)->build($actor, 'studio', $id))->toThrow(AuthorizationException::class);
});
