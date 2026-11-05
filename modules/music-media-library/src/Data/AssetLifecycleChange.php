<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibrary\Data;

use JsonSerializable;

final readonly class AssetLifecycleChange implements JsonSerializable
{
    /** @param list<string> $assetIds */
    public function __construct(public string $workspaceId, public array $assetIds, public string $status, public string $changedAt, public ?string $purgeAfter) {}

    public function jsonSerialize(): array
    {
        return ['schema_version' => 1, 'workspace_id' => $this->workspaceId, 'asset_ids' => $this->assetIds,
            'status' => $this->status, 'changed_at' => $this->changedAt, 'purge_after' => $this->purgeAfter];
    }
}
