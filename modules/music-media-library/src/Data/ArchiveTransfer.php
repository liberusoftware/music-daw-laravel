<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibrary\Data;

use JsonSerializable;

final readonly class ArchiveTransfer implements JsonSerializable
{
    public function __construct(public string $workspaceId, public string $packId, public int $version, public int $bytes, public string $sha256, public int $mediaObjects) {}

    public function jsonSerialize(): array
    {
        return ['schema_version' => 1, 'workspace_id' => $this->workspaceId, 'pack_id' => $this->packId,
            'version' => $this->version, 'bytes' => $this->bytes, 'sha256' => $this->sha256, 'media_objects' => $this->mediaObjects];
    }
}
