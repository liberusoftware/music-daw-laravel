<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibrary\Services;

use HashContext;
use InvalidArgumentException;
use Liberu\Music\MediaLibrary\Data\AudioMetadata;
use Liberu\Music\MediaLibrary\Exceptions\InvalidAudio;

/** Inspects quarantined, seekable streams; callers retain ownership of the stream. */
final class WaveInspector
{
    public function inspect(mixed $stream, int $maxBytes = 1073741824): AudioMetadata
    {
        if (! is_resource($stream) || get_resource_type($stream) !== 'stream' || ! stream_get_meta_data($stream)['seekable']) {
            throw new InvalidArgumentException('Audio inspection requires a seekable stream.');
        }
        $size = fstat($stream)['size'];
        if ($size < 44 || $size > $maxBytes || fseek($stream, 0) !== 0) {
            throw new InvalidAudio('The audio file is empty, too large or cannot be read.');
        }
        $hash = hash_init('sha256');
        $header = $this->read($stream, 12, $hash);
        if (substr($header, 0, 4) !== 'RIFF' || substr($header, 8, 4) !== 'WAVE' || unpack('V', substr($header, 4, 4))[1] !== $size - 8) {
            throw new InvalidAudio('The WAVE container is invalid or incomplete.');
        }
        $remaining = $size - 12;
        $format = null;
        $frames = null;
        $chunks = 0;
        while ($remaining > 0) {
            if ($remaining < 8 || ++$chunks > 4096) {
                throw new InvalidAudio('The WAVE chunk directory is invalid or too large.');
            }
            $chunk = $this->read($stream, 8, $hash);
            $remaining -= 8;
            $kind = substr($chunk, 0, 4);
            $length = unpack('V', substr($chunk, 4, 4))[1];
            $paddedLength = $length + $length % 2;
            if ($paddedLength > $remaining) {
                throw new InvalidAudio('A WAVE chunk extends beyond the complete file.');
            }
            if ($kind === 'fmt ') {
                if ($format !== null || ! in_array($length, [16, 18, 40], true)) {
                    throw new InvalidAudio('The WAVE format chunk is duplicated or unsupported.');
                }
                $format = $this->format($this->read($stream, $length, $hash));
            } else {
                if ($kind === 'data') {
                    if ($format === null || $frames !== null || $length === 0 || $length % $format['alignment'] !== 0) {
                        throw new InvalidAudio('WAVE audio data is missing its format, duplicated or not frame aligned.');
                    }
                    $frames = intdiv($length, $format['alignment']);
                }
                // Unknown metadata chunks are included in the checksum, without being
                // loaded in memory or interpreted as trusted application metadata.
                $unread = $length;
                while ($unread > 0) {
                    $batch = min(65536, $unread);
                    $data = $this->read($stream, $batch, $hash);
                    if ($kind === 'data' && $format['encoding'] === 'ieee-float') {
                        foreach (unpack($format['bits'] === 32 ? 'g*' : 'e*', $data) as $sample) {
                            if (! is_finite($sample)) {
                                throw new InvalidAudio('Non-finite audio samples are not permitted.');
                            }
                        }
                    }
                    $unread -= $batch;
                }
            }
            if ($length % 2 !== 0) {
                $this->read($stream, 1, $hash);
            }
            $remaining -= $paddedLength;
        }
        if ($format === null || $frames === null) {
            throw new InvalidAudio('Both WAVE format and audio data chunks are required.');
        }

        return new AudioMetadata(hash_final($hash), $size, $format['sample_rate'], $format['channels'], $frames, $format['bits'], $format['encoding'], $format['valid_bits'], $format['channel_mask']);
    }

    /** @return array{sample_rate: int, channels: int, alignment: int, bits: int, encoding: string, valid_bits: int, channel_mask: ?int} */
    private function format(string $bytes): array
    {
        $format = unpack('vtag/vchannels/Vrate/Vbyte_rate/valignment/vbits', substr($bytes, 0, 16));
        $tag = $format['tag'];
        $validBits = $format['bits'];
        $channelMask = null;
        if (strlen($bytes) > 16) {
            $extensionLength = unpack('v', substr($bytes, 16, 2))[1];
            if ($extensionLength !== strlen($bytes) - 18) {
                throw new InvalidAudio('The WAVE format extension length is inconsistent.');
            }
        }
        if ($tag === 65534) {
            if (strlen($bytes) !== 40 || substr($bytes, 26, 14) !== hex2bin('000000001000800000aa00389b71')) {
                throw new InvalidAudio('The extensible WAVE subformat is unsupported.');
            }
            $tag = unpack('v', substr($bytes, 24, 2))[1];
            $validBits = unpack('v', substr($bytes, 18, 2))[1];
            if ($validBits < 1 || $validBits > $format['bits'] || ($tag === 3 && $validBits !== $format['bits'])) {
                throw new InvalidAudio('The WAVE valid bit depth is inconsistent.');
            }
            $channelMask = unpack('V', substr($bytes, 20, 4))[1];
            if ($channelMask !== 0 && substr_count(decbin($channelMask), '1') !== $format['channels']) {
                throw new InvalidAudio('The WAVE channel mask does not match its channel count.');
            }
        } elseif (strlen($bytes) === 40) {
            throw new InvalidAudio('Only extensible WAVE may use this format extension.');
        }
        $validEncoding = ($tag === 1 && in_array($format['bits'], [8, 16, 24, 32], true))
            || ($tag === 3 && in_array($format['bits'], [32, 64], true));
        if (! $validEncoding || $format['channels'] < 1 || $format['channels'] > 64 || $format['rate'] < 8000 || $format['rate'] > 384000
            || $format['alignment'] !== $format['channels'] * intdiv($format['bits'], 8)
            || $format['byte_rate'] !== $format['rate'] * $format['alignment']) {
            throw new InvalidAudio('The audio encoding, sample rate, channels or frame alignment is unsupported.');
        }

        return ['sample_rate' => $format['rate'], 'channels' => $format['channels'], 'alignment' => $format['alignment'],
            'bits' => $format['bits'], 'encoding' => $tag === 1 ? 'pcm' : 'ieee-float', 'valid_bits' => $validBits, 'channel_mask' => $channelMask];
    }

    /** @param resource $stream */
    private function read(mixed $stream, int $length, HashContext $hash): string
    {
        $bytes = '';
        while (strlen($bytes) < $length) {
            $chunk = fread($stream, $length - strlen($bytes));
            if ($chunk === false || $chunk === '') {
                throw new InvalidAudio('The audio stream ended before the declared file size.');
            }
            $bytes .= $chunk;
        }
        hash_update($hash, $bytes);

        return $bytes;
    }
}
