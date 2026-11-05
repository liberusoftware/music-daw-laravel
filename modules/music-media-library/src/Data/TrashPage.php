<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibrary\Data;

use JsonSerializable;

final readonly class TrashPage implements JsonSerializable
{
    /** @param list<TrashedAsset> $items */
    public function __construct(public string $workspaceId, public array $items, public ?string $nextAssetId) {}

    public function jsonSerialize(): array
    {
        return ['schema_version' => 1, 'workspace_id' => $this->workspaceId, 'items' => $this->items, 'next_asset_id' => $this->nextAssetId];
    }
}
