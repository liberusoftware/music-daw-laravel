<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibrary\Data;

use JsonSerializable;

final readonly class OfflinePin implements JsonSerializable
{
    public function __construct(public string $workspaceId, public string $pinId, public array $assetIds, public int $version, public ?string $expiresAt) {}

    public function jsonSerialize(): array
    {
        return ['schema_version' => 1, 'workspace_id' => $this->workspaceId, 'pin_id' => $this->pinId,
            'asset_ids' => $this->assetIds, 'version' => $this->version, 'expires_at' => $this->expiresAt];
    }
}
