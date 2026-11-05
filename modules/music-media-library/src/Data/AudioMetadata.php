<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibrary\Data;

use JsonSerializable;

final readonly class AudioMetadata implements JsonSerializable
{
    public function __construct(
        public string $sha256,
        public int $bytes,
        public int $sampleRate,
        public int $channels,
        public int $frames,
        public int $bitsPerSample,
        public string $encoding,
        public int $validBitsPerSample,
        public ?int $channelMask,
    ) {}

    public function jsonSerialize(): array
    {
        return ['sha256' => $this->sha256, 'bytes' => $this->bytes, 'sample_rate' => $this->sampleRate,
            'channels' => $this->channels, 'frames' => $this->frames, 'bits_per_sample' => $this->bitsPerSample,
            'encoding' => $this->encoding, 'valid_bits_per_sample' => $this->validBitsPerSample, 'channel_mask' => $this->channelMask];
    }
}
