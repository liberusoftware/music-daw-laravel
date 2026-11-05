<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibrary\Data;

use JsonSerializable;

final readonly class AssetMetadata implements JsonSerializable
{
    public function __construct(public string $assetId, public string $name, public array $tags, public int $version) {}

    public function jsonSerialize(): array
    {
        return ['asset_id' => $this->assetId, 'name' => $this->name, 'tags' => $this->tags, 'version' => $this->version];
    }
}
