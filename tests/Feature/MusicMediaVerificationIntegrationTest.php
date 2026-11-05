<?php

use Liberu\Music\MediaLibrary\Exceptions\InvalidAudio;
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
