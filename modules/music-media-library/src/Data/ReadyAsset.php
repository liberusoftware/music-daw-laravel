<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibrary\Data;

final readonly class ReadyAsset
{
    public function __construct(
        public string $id,
        public string $workspaceId,
        public string $name,
        public array $metadata,
        public array $provenance,
        public ?string $duplicateOf,
    ) {}

    /** @return array{id: string, sha256: string, sample_rate: int, channels: int, frames: int} */
    public function reference(): array
    {
        return ['id' => $this->id, 'sha256' => $this->metadata['sha256'], 'sample_rate' => $this->metadata['sample_rate'],
            'channels' => $this->metadata['channels'], 'frames' => $this->metadata['frames']];
    }
}
