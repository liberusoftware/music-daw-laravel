<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibrary\Data;

use JsonSerializable;

final readonly class MediaPackRevision implements JsonSerializable
{
    public function __construct(public int $version, public string $name, public int $assetCount, public string $createdAt) {}

    public function jsonSerialize(): array
    {
        return ['schema_version' => 1, 'version' => $this->version, 'name' => $this->name, 'asset_count' => $this->assetCount, 'created_at' => $this->createdAt];
    }
}
