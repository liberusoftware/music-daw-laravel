<?php

use Liberu\Music\MediaLibrary\Exceptions\InvalidAudio;
use Liberu\Music\MediaLibrary\Services\WaveInspector;
use Liberu\Music\MediaLibrary\Tests\Fixtures\TruncatedAudioFilter;

function waveChunk(string $type, string $bytes): string
{
    return $type.pack('V', strlen($bytes)).$bytes.(strlen($bytes) % 2 ? "\0" : '');
}

function waveContainer(string $chunks): string
{
    return 'RIFF'.pack('V', strlen($chunks) + 4).'WAVE'.$chunks;
}

function waveFormat(int $tag = 1, int $bits = 16, int $channels = 2, int $rate = 48000): string
{
    $alignment = $channels * intdiv($bits, 8);

    return pack('vvVVvv', $tag, $channels, $rate, $alignment * $rate, $alignment, $bits);
}

function waveStream(string $bytes): mixed
{
    $stream = fopen('php://temp', 'w+b');
    fwrite($stream, $bytes);

    return $stream;
}

function inspectWave(string $bytes): mixed
{
    $stream = waveStream($bytes);
    try {
        return (new WaveInspector())->inspect($stream);
    } finally {
        fclose($stream);
    }
}

it('derives immutable metadata and content address from actual audio bytes', function () {
    $bytes = waveContainer(waveChunk('fmt ', waveFormat()).waveChunk('data', pack('v*', 0, 32767, 65535, 16384)));
    $metadata = inspectWave($bytes);
    expect($metadata->jsonSerialize())->toBe([
        'sha256' => hash('sha256', $bytes), 'bytes' => strlen($bytes), 'sample_rate' => 48000,
        'channels' => 2, 'frames' => 2, 'bits_per_sample' => 16, 'encoding' => 'pcm', 'valid_bits_per_sample' => 16, 'channel_mask' => null,
    ]);
    expect(json_decode(json_encode($metadata, JSON_THROW_ON_ERROR), true))->toBe($metadata->jsonSerialize());
    expect(fn () => $metadata->frames = 9)->toThrow(Error::class);
});

it('supports declared PCM container depths with frame counts measured in samples per channel', function (int $bits, int $channels) {
    $data = str_repeat("\0", 7 * $channels * intdiv($bits, 8));
    $result = inspectWave(waveContainer(waveChunk('fmt ', waveFormat(bits: $bits, channels: $channels)).waveChunk('data', $data)));
    expect($result->frames)->toBe(7)->and($result->bitsPerSample)->toBe($bits)->and($result->channels)->toBe($channels);
})->with([[8, 1], [16, 2], [24, 2], [32, 2], [24, 16], [16, 64]]);

it('supports finite little endian floating point samples', function (int $bits, string $samples) {
    $result = inspectWave(waveContainer(waveChunk('fmt ', waveFormat(3, $bits).pack('v', 0)).waveChunk('data', $samples)));
    expect($result->encoding)->toBe('ieee-float')->and($result->frames)->toBe(2)->and($result->bitsPerSample)->toBe($bits);
})->with([[32, pack('g*', -1.0, 0.0, 0.25, 1.5)], [64, pack('e*', -1.0, 0.0, 0.25, 1.5)]]);

it('rejects nonfinite floating point samples before publication', function (int $bits, string $samples) {
    expect(fn () => inspectWave(waveContainer(waveChunk('fmt ', waveFormat(3, $bits)).waveChunk('data', $samples))))->toThrow(InvalidAudio::class, 'Non-finite');
})->with([[32, pack('g*', NAN, 0.0)], [32, pack('g*', INF, 0.0)], [64, pack('e*', -INF, 0.0)]]);

it('accepts extensible PCM and float GUIDs', function (int $tag, int $bits, int $validBits, string $samples) {
    $extension = pack('vvV', 22, $validBits, 3).pack('v', $tag).hex2bin('000000001000800000aa00389b71');
    $result = inspectWave(waveContainer(waveChunk('fmt ', waveFormat(65534, $bits).$extension).waveChunk('data', $samples)));
    expect($result->frames)->toBe(1)->and($result->bitsPerSample)->toBe($bits)->and($result->validBitsPerSample)->toBe($validBits)->and($result->channelMask)->toBe(3);
})->with([[1, 32, 24, str_repeat("\0", 8)], [3, 32, 32, pack('g*', 0.0, 1.0)]]);

it('walks padded and large metadata chunks and includes their bytes in the checksum', function () {
    $bytes = waveContainer(waveChunk('JUNK', 'abc').waveChunk('fmt ', waveFormat().pack('v', 0)).waveChunk('LIST', str_repeat('x', 150000)).waveChunk('data', str_repeat("\0", 131072)).waveChunk('tail', 'z'));
    $result = inspectWave($bytes);
    expect($result->sha256)->toBe(hash('sha256', $bytes))->and($result->frames)->toBe(32768);
});

it('leaves the caller stream open and resets its position before inspection', function () {
    $bytes = waveContainer(waveChunk('fmt ', waveFormat()).waveChunk('data', str_repeat("\0", 4)));
    $stream = waveStream($bytes);
    try {
        expect((new WaveInspector())->inspect($stream)->bytes)->toBe(strlen($bytes));
        expect(is_resource($stream))->toBeTrue()->and(ftell($stream))->toBe(strlen($bytes));
        expect(fn () => (new WaveInspector())->inspect($stream, strlen($bytes) - 1))->toThrow(InvalidAudio::class, 'too large');
    } finally {
        fclose($stream);
    }
});

it('rejects malformed containers, chunks and unsupported formats', function (Closure $build) {
    expect(fn () => inspectWave($build()))->toThrow(InvalidAudio::class);
})->with([
    'short input' => [fn () => 'RIFF'],
    'wrong container' => [fn () => str_repeat('x', 44)],
    'wrong WAVE type' => [fn () => 'RIFF'.pack('V', 36).'RIFX'.str_repeat('x', 32)],
    'declared length mismatch' => [fn () => 'RIFF'.pack('V', 99).'WAVE'.str_repeat('x', 32)],
    'partial chunk header' => [fn () => waveContainer(waveChunk('fmt ', waveFormat()).waveChunk('data', '1234').'x')],
    'overrunning chunk' => [fn () => waveContainer(waveChunk('fmt ', waveFormat()).'data'.pack('V', 10).'1234')],
    'missing audio' => [fn () => waveContainer(waveChunk('fmt ', waveFormat()).waveChunk('JUNK', '12345678'))],
    'missing format' => [fn () => waveContainer(waveChunk('JUNK', str_repeat('x', 32)))],
    'data before format' => [fn () => waveContainer(waveChunk('data', '1234').waveChunk('fmt ', waveFormat()))],
    'duplicate format' => [fn () => waveContainer(waveChunk('fmt ', waveFormat()).waveChunk('fmt ', waveFormat()).waveChunk('data', '1234'))],
    'duplicate data' => [fn () => waveContainer(waveChunk('fmt ', waveFormat()).waveChunk('data', '1234').waveChunk('data', '1234'))],
    'empty audio' => [fn () => waveContainer(waveChunk('fmt ', waveFormat()).waveChunk('data', '').waveChunk('JUNK', '1234'))],
    'partial frame' => [fn () => waveContainer(waveChunk('fmt ', waveFormat()).waveChunk('data', '123'))],
    'unsupported format length' => [fn () => waveContainer(waveChunk('fmt ', str_repeat('x', 20)).waveChunk('data', '1234'))],
    'extension length mismatch' => [fn () => waveContainer(waveChunk('fmt ', waveFormat().pack('v', 10)).waveChunk('data', '1234'))],
    'unsupported encoding' => [fn () => waveContainer(waveChunk('fmt ', waveFormat(tag: 7)).waveChunk('data', '1234'))],
    'unsupported depth' => [fn () => waveContainer(waveChunk('fmt ', waveFormat(bits: 12)).waveChunk('data', '1234'))],
    'invalid channels' => [fn () => waveContainer(waveChunk('fmt ', waveFormat(channels: 0)).waveChunk('data', '1234'))],
    'invalid rate' => [fn () => waveContainer(waveChunk('fmt ', waveFormat(rate: 7999)).waveChunk('data', '1234'))],
    'invalid alignment' => [fn () => waveContainer(waveChunk('fmt ', pack('vvVVvv', 1, 2, 48000, 192000, 2, 16)).waveChunk('data', '1234'))],
    'invalid byte rate' => [fn () => waveContainer(waveChunk('fmt ', pack('vvVVvv', 1, 2, 48000, 1000, 4, 16)).waveChunk('data', '1234'))],
    'short extensible format' => [fn () => waveContainer(waveChunk('fmt ', waveFormat(65534)).waveChunk('data', '1234'))],
    'wrong extensible GUID' => [fn () => waveContainer(waveChunk('fmt ', waveFormat(65534).pack('v', 22).str_repeat("\0", 22)).waveChunk('data', '1234'))],
    'invalid valid bit depth' => [fn () => waveContainer(waveChunk('fmt ', waveFormat(65534).pack('vvVv', 22, 24, 3, 1).hex2bin('000000001000800000aa00389b71')).waveChunk('data', '1234'))],
    'mismatched channel mask' => [fn () => waveContainer(waveChunk('fmt ', waveFormat(65534).pack('vvVv', 22, 16, 1, 1).hex2bin('000000001000800000aa00389b71')).waveChunk('data', '1234'))],
    'extension on wrong format' => [fn () => waveContainer(waveChunk('fmt ', waveFormat().pack('v', 22).str_repeat("\0", 22)).waveChunk('data', '1234'))],
    'excessive chunk count' => [fn () => waveContainer(str_repeat(waveChunk('JUNK', ''), 4097).waveChunk('fmt ', waveFormat()).waveChunk('data', '1234'))],
]);

it('rejects missing, closed or nonseekable streams', function () {
    $closed = fopen('php://temp', 'w+b');
    fclose($closed);
    foreach ([null, 'filename.wav', $closed] as $invalid) {
        expect(fn () => (new WaveInspector())->inspect($invalid))->toThrow(InvalidArgumentException::class);
    }
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    try {
        expect(fn () => (new WaveInspector())->inspect($pair[0]))->toThrow(InvalidArgumentException::class);
    } finally {
        fclose($pair[0]);
        fclose($pair[1]);
    }
});

it('refuses an interrupted read even when the initial file size was complete', function () {
    stream_filter_register('music.audio.truncated', TruncatedAudioFilter::class);
    $bytes = waveContainer(waveChunk('fmt ', waveFormat()).waveChunk('data', '1234'));
    $stream = waveStream($bytes);
    stream_filter_append($stream, 'music.audio.truncated', STREAM_FILTER_READ);
    try {
        expect(fn () => (new WaveInspector())->inspect($stream))->toThrow(InvalidAudio::class, 'stream ended');
    } finally {
        fclose($stream);
    }
});
