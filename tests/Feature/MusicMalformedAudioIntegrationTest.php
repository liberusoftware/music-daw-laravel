<?php

use App\Models\User;
use App\Providers\MusicModuleIntegrationProvider;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\RecordNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Liberu\Foundation\Files\Contracts\MalwareScanner;
use Liberu\Music\MediaLibrary\Commands\StartUpload;
use Liberu\Music\MediaLibrary\Contracts\MediaAuthorizer;
use Liberu\Music\MediaLibrary\Events\AssetReady;
use Liberu\Music\MediaLibrary\Exceptions\InvalidAudio;
use Liberu\Music\MediaLibrary\MusicMediaLibraryServiceProvider;
use Liberu\Music\MediaLibrary\Services\MediaUploads;
use Liberu\Music\MediaLibrary\Services\WaveInspector;
use Liberu\Music\MediaLibrary\Support\GateMediaAuthorizer;
use Liberu\Music\Projects\Commands\SaveSession;
use Liberu\Music\Projects\Data\SessionDocument;
use Liberu\Music\Projects\Services\ProjectSessions;

function hostMalformedWaveChunk(string $kind, string $payload): string
{
    return $kind.pack('V', strlen($payload)).$payload.(strlen($payload) % 2 ? "\0" : '');
}

function hostMalformedWave(string $chunks): string
{
    return 'RIFF'.pack('V', strlen($chunks) + 4).'WAVE'.$chunks;
}

it('keeps malformed uploaded audio quarantined and out of immutable session revisions', function (Closure $fixture) {
    $this->app->register(MusicMediaLibraryServiceProvider::class);
    (new MusicModuleIntegrationProvider($this->app))->boot();
    // This contract fixture supplies synthetic workspace policies. Real host
    // membership/permission enforcement is covered by MusicMediaWorkspaceAuthorizationTest.
    app()->bind(MediaAuthorizer::class, GateMediaAuthorizer::class);
    $this->artisan('migrate', ['--path' => base_path('modules/music-media-library/database/migrations'), '--realpath' => true])->assertSuccessful();
    config()->set('music-media.disk', 'malformed-host-audio');
    $disk = Storage::fake('malformed-host-audio');
    $actor = User::factory()->create();
    foreach (['music.media.upload', 'music.media.read', 'music.media.retain', 'music.projects.write'] as $ability) {
        Gate::define($ability, fn ($user, $workspace) => $user->is($actor) && $workspace === 'studio');
    }
    // A clean malware scan is necessary, but cannot replace byte-format verification.
    $scanner = Mockery::mock(MalwareScanner::class);
    $scanner->shouldReceive('clean')->twice()->andReturnTrue();
    app()->instance(MalwareScanner::class, $scanner);
    Event::fake([AssetReady::class]);
    $format = pack('vvVVvv', 1, 2, 48000, 192000, 4, 16);
    $bytes = $fixture($format);
    $id = (string) Str::uuid();
    $uploads = app(MediaUploads::class);
    $uploads->start($actor, new StartUpload('studio', $id, 'Untrusted recording.wav', strlen($bytes), hash('sha256', $bytes)));
    $uploads->append($actor, 'studio', $id, 0, $bytes);
    // Retrying completion must never publish a partially verified asset.
    for ($attempt = 0; $attempt < 2; $attempt++) {
        expect(fn () => $uploads->complete($actor, 'studio', $id))->toThrow(InvalidAudio::class);
        expect(fn () => $uploads->get($actor, 'studio', $id))->toThrow(RecordNotFoundException::class);
    }
    $document = SessionDocument::empty()->jsonSerialize();
    $document['assets'] = [['id' => $id, 'sha256' => hash('sha256', $bytes), 'sample_rate' => 48000, 'channels' => 2, 'frames' => 1]];
    $document['tracks'] = [['id' => (string) Str::uuid(), 'name' => 'Unverified take', 'kind' => 'audio', 'parent_id' => null, 'media_ids' => [$id]]];
    expect(fn () => app(ProjectSessions::class)->save($actor, new SaveSession('studio', (string) Str::uuid(), 'Unsafe take', SessionDocument::fromArray($document), 0, 'checkpoint')))
        ->toThrow(AuthorizationException::class);
    expect(DB::table('music_media_uploads')->where('id', $id)->value('status'))->toBe('uploading')
        ->and(DB::table('music_media_uploads')->where('id', $id)->value('blob_key'))->toBeNull()
        ->and($disk->allFiles('music/assets'))->toBe([])
        ->and(DB::table('music_project_revisions')->count())->toBe(0)
        ->and(DB::table('music_media_retention')->count())->toBe(0);
    Event::assertNotDispatched(AssetReady::class);
})->with([
    'declared RIFF length differs from received bytes' => [fn ($fmt) => 'RIFF'.pack('V', 1).'WAVE'.hostMalformedWaveChunk('fmt ', $fmt).hostMalformedWaveChunk('data', str_repeat("\0", 4))],
    'chunk extends beyond file' => [fn ($fmt) => hostMalformedWave(hostMalformedWaveChunk('fmt ', $fmt).'data'.pack('V', 1000).str_repeat("\0", 4))],
    'duplicated format' => [fn ($fmt) => hostMalformedWave(hostMalformedWaveChunk('fmt ', $fmt).hostMalformedWaveChunk('fmt ', $fmt).hostMalformedWaveChunk('data', str_repeat("\0", 4)))],
    'audio precedes format' => [fn ($fmt) => hostMalformedWave(hostMalformedWaveChunk('data', str_repeat("\0", 4)).hostMalformedWaveChunk('fmt ', $fmt))],
    'partial stereo frame' => [fn ($fmt) => hostMalformedWave(hostMalformedWaveChunk('fmt ', $fmt).hostMalformedWaveChunk('data', "\0\0").hostMalformedWaveChunk('JUNK', 'pad'))],
    'empty data' => [fn ($fmt) => hostMalformedWave(hostMalformedWaveChunk('fmt ', $fmt).hostMalformedWaveChunk('data', '').hostMalformedWaveChunk('JUNK', 'pad'))],
    'missing data' => [fn ($fmt) => hostMalformedWave(hostMalformedWaveChunk('fmt ', $fmt).hostMalformedWaveChunk('JUNK', str_repeat('x', 8)))],
    'duplicate data' => [fn ($fmt) => hostMalformedWave(hostMalformedWaveChunk('fmt ', $fmt).str_repeat(hostMalformedWaveChunk('data', str_repeat("\0", 4)), 2))],
    'unsupported format length' => [fn ($fmt) => hostMalformedWave(hostMalformedWaveChunk('fmt ', $fmt.str_repeat("\0", 4)).hostMalformedWaveChunk('data', str_repeat("\0", 4)))],
    'inconsistent extension length' => [fn ($fmt) => hostMalformedWave(hostMalformedWaveChunk('fmt ', $fmt.pack('v', 2)).hostMalformedWaveChunk('data', str_repeat("\0", 4)))],
    'nonfinite float samples' => [fn () => hostMalformedWave(hostMalformedWaveChunk('fmt ', pack('vvVVvv', 3, 1, 48000, 192000, 4, 32)).hostMalformedWaveChunk('data', pack('g', NAN)))],
    'unsupported encoding' => [fn ($fmt) => hostMalformedWave(hostMalformedWaveChunk('fmt ', pack('v', 99).substr($fmt, 2)).hostMalformedWaveChunk('data', str_repeat("\0", 4)))],
    'inconsistent extensible bit depth' => [fn () => hostMalformedWave(hostMalformedWaveChunk('fmt ', pack('vvVVvv', 65534, 2, 48000, 192000, 4, 16).pack('vvV', 22, 0, 3).hex2bin('0100000000001000800000aa00389b71')).hostMalformedWaveChunk('data', str_repeat("\0", 4)))],
    'inconsistent extensible channel mask' => [fn () => hostMalformedWave(hostMalformedWaveChunk('fmt ', pack('vvVVvv', 65534, 2, 48000, 192000, 4, 16).pack('vvV', 22, 16, 1).hex2bin('0100000000001000800000aa00389b71')).hostMalformedWaveChunk('data', str_repeat("\0", 4)))],
    'trailing incomplete chunk header' => [fn ($fmt) => hostMalformedWave(hostMalformedWaveChunk('fmt ', $fmt).hostMalformedWaveChunk('data', str_repeat("\0", 4)).'x')],
    'excessive chunk directory' => [fn ($fmt) => hostMalformedWave(hostMalformedWaveChunk('fmt ', $fmt).str_repeat(hostMalformedWaveChunk('JUNK', ''), 4096).hostMalformedWaveChunk('data', str_repeat("\0", 4)))],
]);

it('derives supported extensible and floating point metadata while hashing unknown padded chunks', function (int $tag, int $bits, string $samples, bool $extensible) {
    $format = pack('vvVVvv', $extensible ? 65534 : $tag, 2, 48000, 48000 * 2 * intdiv($bits, 8), 2 * intdiv($bits, 8), $bits);
    if ($extensible) {
        $format .= pack('vvVv', 22, $bits, 3, $tag).hex2bin('000000001000800000aa00389b71');
    } else {
        $format .= pack('v', 0);
    }
    $prefix = hostMalformedWaveChunk('JUNK', 'odd').hostMalformedWaveChunk('fmt ', $format);
    $bytes = hostMalformedWave($prefix.hostMalformedWaveChunk('data', $samples).hostMalformedWaveChunk('LIST', 'untrusted metadata'));
    $stream = fopen('php://temp', 'w+b');
    fwrite($stream, $bytes);
    try {
        $metadata = app(WaveInspector::class)->inspect($stream);
        expect($metadata->sha256)->toBe(hash('sha256', $bytes))->and($metadata->bytes)->toBe(strlen($bytes))
            ->and($metadata->sampleRate)->toBe(48000)->and($metadata->channels)->toBe(2)->and($metadata->frames)->toBe(2)
            ->and($metadata->bitsPerSample)->toBe($bits)->and($metadata->validBitsPerSample)->toBe($bits)
            ->and($metadata->encoding)->toBe($tag === 1 ? 'pcm' : 'ieee-float')
            ->and($metadata->channelMask)->toBe($extensible ? 3 : null)
            ->and($metadata->dataOffsetBytes)->toBe(12 + strlen($prefix) + 8);
        expect(is_resource($stream))->toBeTrue();
    } finally {
        fclose($stream);
    }
})->with([
    'extensible PCM' => [1, 24, str_repeat("\0", 12), true],
    'extensible float32' => [3, 32, pack('g*', -1.0, 0.0, 0.5, 1.25), true],
    'float32' => [3, 32, pack('g*', -1.0, 0.0, 0.5, 1.25), false],
    'float64' => [3, 64, pack('e*', -1.0, 0.0, 0.5, 1.25), false],
]);
