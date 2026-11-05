<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibrary\Data;

use JsonSerializable;

final readonly class Waveform implements JsonSerializable
{
    /** @param list<array{start_sample_frame: int, end_sample_frame: int, channels: list<array{minimum: float, maximum: float}>}> $peaks */
    public function __construct(public AudioMetadata $audio, public int $framesPerPeak, public array $peaks) {}

    public function jsonSerialize(): array
    {
        return ['schema_version' => 1, 'source_sha256' => $this->audio->sha256,
            'sample_rate' => $this->audio->sampleRate, 'channels' => $this->audio->channels,
            'channel_mask' => $this->audio->channelMask, 'sample_frames' => $this->audio->frames,
            'sample_frames_per_peak' => $this->framesPerPeak, 'peaks' => $this->peaks];
    }
}
