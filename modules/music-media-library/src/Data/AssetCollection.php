<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibrary\Data;

use JsonSerializable;

final readonly class AssetCollection implements JsonSerializable
{
    /** @param list<string> $assetIds */
    public function __construct(public string $workspaceId, public bool $dryRun, public array $assetIds, public int $declaredBytes, public ?string $nextAssetId) {}

    public function jsonSerialize(): array
    {
        return ['schema_version' => 1, 'workspace_id' => $this->workspaceId, 'dry_run' => $this->dryRun,
            'asset_ids' => $this->assetIds, 'declared_bytes' => $this->declaredBytes, 'next_asset_id' => $this->nextAssetId];
    }
}
