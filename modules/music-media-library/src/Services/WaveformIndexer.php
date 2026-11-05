<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibrary\Services;

use InvalidArgumentException;
use Liberu\Music\MediaLibrary\Data\AudioMetadata;
use Liberu\Music\MediaLibrary\Data\Waveform;
use Liberu\Music\MediaLibrary\Exceptions\InvalidAudio;

/** Offline preview work. Never invoke from an audio callback or meter loop. */
final readonly class WaveformIndexer
{
    public function __construct(private WaveInspector $inspector) {}

    public function build(mixed $stream, int $framesPerPeak = 1024): Waveform
    {
        if ($framesPerPeak < 1 || $framesPerPeak > 1048576) {
            throw new InvalidArgumentException('Waveform windows must contain between 1 and 1048576 sample frames.');
        }
        $audio = $this->inspector->inspect($stream);
        $maxWindows = max(1, intdiv(16384, $audio->channels));
        $framesPerPeak = max($framesPerPeak, intdiv($audio->frames + $maxWindows - 1, $maxWindows));
        if (fseek($stream, $audio->dataOffsetBytes) !== 0) {
            throw new InvalidAudio('The verified audio data cannot be read for waveform indexing.');
        }
        $sampleBytes = intdiv($audio->bitsPerSample, 8);
        $frameBytes = $audio->channels * $sampleBytes;
        $batchLimit = max(1, intdiv(65536, $frameBytes));
        $peaks = [];
        for ($start = 0; $start < $audio->frames; $start += $framesPerPeak) {
            $end = min($audio->frames, $start + $framesPerPeak);
            $minimum = array_fill(0, $audio->channels, INF);
            $maximum = array_fill(0, $audio->channels, -INF);
            $remaining = $end - $start;
            while ($remaining > 0) {
                $frames = min($remaining, $batchLimit);
                $data = $this->read($stream, $frames * $frameBytes);
                $length = strlen($data);
                for ($position = 0; $position < $length; $position += $sampleBytes) {
                    $channel = intdiv($position, $sampleBytes) % $audio->channels;
                    $sample = $this->sample(substr($data, $position, $sampleBytes), $audio);
                    if (! is_finite($sample)) {
                        throw new InvalidAudio('Non-finite samples cannot be indexed.');
                    }
                    $minimum[$channel] = min($minimum[$channel], $sample);
                    $maximum[$channel] = max($maximum[$channel], $sample);
                }
                $remaining -= $frames;
            }
            $channels = [];
            for ($channel = 0; $channel < $audio->channels; $channel++) {
                $channels[] = ['minimum' => $minimum[$channel], 'maximum' => $maximum[$channel]];
            }
            $peaks[] = ['start_sample_frame' => $start, 'end_sample_frame' => $end, 'channels' => $channels];
        }
        // The source must still be the inspected immutable asset. A file changed
        // during indexing must not acquire a preview bearing the old checksum.
        if (! hash_equals($audio->sha256, $this->inspector->inspect($stream)->sha256)) {
            throw new InvalidAudio('The source audio changed during waveform indexing.');
        }

        return new Waveform($audio, $framesPerPeak, $peaks);
    }

    private function sample(string $bytes, AudioMetadata $audio): float
    {
        if ($audio->encoding === 'ieee-float') {
            return unpack($audio->bitsPerSample === 32 ? 'g' : 'e', $bytes)[1];
        }
        if ($audio->bitsPerSample === 8) {
            return (ord($bytes) - 128) / 128;
        }
        // Zero-extend the verified 16/24/32-bit little-endian PCM container.
        $value = unpack('V', str_pad($bytes, 4, "\0"))[1];
        $midpoint = 2 ** ($audio->bitsPerSample - 1);
        if ($value >= $midpoint) {
            $value -= $midpoint * 2;
        }

        return $value / $midpoint;
    }

    /** @param resource $stream */
    private function read(mixed $stream, int $length): string
    {
        $bytes = '';
        while (strlen($bytes) < $length) {
            $part = fread($stream, $length - strlen($bytes));
            if ($part === false || $part === '') {
                throw new InvalidAudio('The source audio ended during waveform indexing.');
            }
            $bytes .= $part;
        }

        return $bytes;
    }
}
