<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibrary\Data;

use JsonSerializable;

final readonly class AssetPage implements JsonSerializable
{
    /** @param list<CatalogEntry> $items */
    public function __construct(public array $items, public ?string $nextAssetId) {}

    public function jsonSerialize(): array
    {
        return ['schema_version' => 1, 'items' => $this->items, 'next_asset_id' => $this->nextAssetId];
    }
}
