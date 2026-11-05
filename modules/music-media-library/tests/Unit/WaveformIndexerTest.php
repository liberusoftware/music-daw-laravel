<?php

use Liberu\Music\MediaLibrary\Exceptions\InvalidAudio;
use Liberu\Music\MediaLibrary\Services\WaveformIndexer;
use Liberu\Music\MediaLibrary\Tests\Fixtures\ChangingAudioStream;

function waveformBytes(string $data, int $bits = 16, int $channels = 1, int $tag = 1, string $before = ''): string
{
    $frameBytes = $channels * intdiv($bits, 8);
    $format = pack('vvVVvv', $tag, $channels, 48000, 48000 * $frameBytes, $frameBytes, $bits);
    $chunks = $before.'fmt '.pack('V', 16).$format.'data'.pack('V', strlen($data)).$data.(strlen($data) % 2 ? "\0" : '');

    return 'RIFF'.pack('V', strlen($chunks) + 4).'WAVE'.$chunks;
}

function indexWaveform(string $bytes, int $framesPerPeak = 1024): mixed
{
    $stream = fopen('php://temp', 'w+b');
    fwrite($stream, $bytes);
    try {
        return app(WaveformIndexer::class)->build($stream, $framesPerPeak);
    } finally {
        fclose($stream);
    }
}

it('indexes each channel independently with explicit half open sample frame positions', function () {
    $samples = pack('v*', 0, 16384, 32768, 8192, 16384, 57344, 8192, 49152, 4096, 4096);
    $bytes = waveformBytes($samples, channels: 2);
    $waveform = indexWaveform($bytes, 2);
    expect($waveform->framesPerPeak)->toBe(2)->and($waveform->audio->frames)->toBe(5)
        ->and($waveform->audio->sha256)->toBe(hash('sha256', $bytes))
        ->and($waveform->peaks)->toBe([
            ['start_sample_frame' => 0, 'end_sample_frame' => 2, 'channels' => [['minimum' => -1.0, 'maximum' => 0.0], ['minimum' => 0.25, 'maximum' => 0.5]]],
            ['start_sample_frame' => 2, 'end_sample_frame' => 4, 'channels' => [['minimum' => 0.25, 'maximum' => 0.5], ['minimum' => -0.5, 'maximum' => -0.25]]],
            ['start_sample_frame' => 4, 'end_sample_frame' => 5, 'channels' => [['minimum' => 0.125, 'maximum' => 0.125], ['minimum' => 0.125, 'maximum' => 0.125]]],
        ]);
    $serialized = json_decode(json_encode($waveform, JSON_THROW_ON_ERROR), true);
    expect($serialized['schema_version'])->toBe(1)->and($serialized['source_sha256'])->toBe(hash('sha256', $bytes))
        ->and($serialized['sample_rate'])->toBe(48000)->and($serialized['channels'])->toBe(2)
        ->and($serialized['channel_mask'])->toBeNull()->and($serialized['sample_frames'])->toBe(5)
        ->and($serialized['sample_frames_per_peak'])->toBe(2)->and($serialized['peaks'][2]['end_sample_frame'])->toBe(5);
    expect(fn () => $waveform->framesPerPeak = 99)->toThrow(Error::class);
});

it('normalizes unsigned and signed PCM sample containers consistently', function (int $bits, string $samples) {
    $waveform = indexWaveform(waveformBytes($samples, $bits));
    expect($waveform->peaks)->toBe([['start_sample_frame' => 0, 'end_sample_frame' => 4, 'channels' => [['minimum' => -1.0, 'maximum' => 0.5]]]]);
})->with([
    [8, pack('C*', 0, 128, 192, 64)],
    [16, pack('v*', 32768, 0, 16384, 49152)],
    [24, substr(pack('V', 8388608), 0, 3).substr(pack('V', 0), 0, 3).substr(pack('V', 4194304), 0, 3).substr(pack('V', 12582912), 0, 3)],
    [32, pack('V*', 2147483648, 0, 1073741824, 3221225472)],
]);

it('preserves floating point headroom instead of clipping the preview to unity', function (int $bits, string $samples) {
    $waveform = indexWaveform(waveformBytes($samples, $bits, tag: 3));
    expect($waveform->peaks[0]['channels'])->toBe([['minimum' => -1.5, 'maximum' => 2.0]]);
})->with([[32, pack('g*', -1.5, 0.0, 2.0)], [64, pack('e*', -1.5, 0.0, 2.0)]]);

it('uses the actual data location after padded metadata chunks', function () {
    $before = 'JUNK'.pack('V', 3).'abc'."\0";
    $waveform = indexWaveform(waveformBytes(pack('v*', 0, 16384), before: $before), 1);
    expect($waveform->audio->dataOffsetBytes)->toBe(56)
        ->and($waveform->peaks[0]['channels'][0])->toBe(['minimum' => 0.0, 'maximum' => 0.0])
        ->and($waveform->peaks[1]['channels'][0])->toBe(['minimum' => 0.5, 'maximum' => 0.5]);
});

it('bounds total points for long or multichannel material without dropping tail frames', function () {
    $waveform = indexWaveform(waveformBytes(str_repeat(pack('v', 16384), 20001)), 1);
    expect($waveform->framesPerPeak)->toBe(2)->and($waveform->peaks)->toHaveCount(10001)
        ->and($waveform->peaks[10000]['start_sample_frame'])->toBe(20000)
        ->and($waveform->peaks[10000]['end_sample_frame'])->toBe(20001);
    $manyChannels = indexWaveform(waveformBytes(str_repeat("\0", 1000 * 64 * 2), channels: 64), 1);
    expect($manyChannels->framesPerPeak)->toBe(4)->and($manyChannels->peaks)->toHaveCount(250)
        ->and(count($manyChannels->peaks) * $manyChannels->audio->channels)->toBeLessThanOrEqual(16384)
        ->and($manyChannels->peaks[249]['end_sample_frame'])->toBe(1000);
});

it('retains channel alignment across streaming buffer boundaries', function () {
    $data = str_repeat("\0", 10922 * 6).pack('v*', 16384, 49152, 8192).str_repeat("\0", 100 * 6);
    $waveform = indexWaveform(waveformBytes($data, channels: 3), 20000);
    expect($waveform->peaks)->toHaveCount(1)->and($waveform->peaks[0]['end_sample_frame'])->toBe(11023)
        ->and($waveform->peaks[0]['channels'])->toBe([
            ['minimum' => 0.0, 'maximum' => 0.5], ['minimum' => -0.5, 'maximum' => 0.0], ['minimum' => 0.0, 'maximum' => 0.25],
        ]);
});

it('rejects invalid indexing windows', function (int $window) {
    expect(fn () => indexWaveform(waveformBytes(pack('v', 0)), $window))->toThrow(InvalidArgumentException::class);
})->with([0, -1, 1048577]);

it('refuses source changes, failed seeks and interrupted reads during indexing', function (string $mode, string $message) {
    if (! in_array('musicwave', stream_get_wrappers(), true)) {
        stream_wrapper_register('musicwave', ChangingAudioStream::class);
    }
    ChangingAudioStream::$mode = $mode;
    ChangingAudioStream::$bytes = $mode === 'nonfinite'
        ? waveformBytes(pack('g*', 0.0, 1.0), 32, tag: 3)
        : waveformBytes(pack('v*', 0, 16384));
    $stream = fopen('musicwave://source', 'rb');
    stream_set_chunk_size($stream, 1);
    try {
        expect(fn () => app(WaveformIndexer::class)->build($stream))->toThrow(InvalidAudio::class, $message);
    } finally {
        fclose($stream);
    }
})->with([
    ['failed-seek', 'cannot be read'], ['truncated', 'ended during'], ['changed', 'changed during'], ['nonfinite', 'Non-finite'],
]);

it('normalizes left aligned valid PCM bits and preserves the extensible channel mask', function () {
    $format = pack('vvVVvv', 65534, 2, 48000, 384000, 8, 32)
        .pack('vvVv', 22, 24, 3, 1).hex2bin('000000001000800000aa00389b71');
    $data = pack('V*', 2147483648, 1073741824);
    $chunks = 'fmt '.pack('V', 40).$format.'data'.pack('V', strlen($data)).$data;
    $waveform = indexWaveform('RIFF'.pack('V', strlen($chunks) + 4).'WAVE'.$chunks);
    expect($waveform->audio->validBitsPerSample)->toBe(24)->and($waveform->jsonSerialize()['channel_mask'])->toBe(3)
        ->and($waveform->peaks[0]['channels'])->toBe([['minimum' => -1.0, 'maximum' => -1.0], ['minimum' => 0.5, 'maximum' => 0.5]]);
});
