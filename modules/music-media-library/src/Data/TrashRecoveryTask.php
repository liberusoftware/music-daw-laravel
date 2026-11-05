<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibrary\Data;

use JsonSerializable;

final readonly class TrashRecoveryTask implements JsonSerializable
{
    public function __construct(public string $id, public string $workspaceId, public string $assetId, public string $status) {}

    public function jsonSerialize(): array
    {
        return ['schema_version' => 1, 'id' => $this->id, 'workspace_id' => $this->workspaceId, 'asset_id' => $this->assetId,
            'kind' => 'recover_trash', 'status' => $this->status, 'error_code' => $this->status === 'failed' ? 'trash_recovery_failed' : null,
            'terminal' => $this->status !== 'queued', 'cancellable' => $this->status === 'queued',
            'progress' => ['completed_steps' => $this->status === 'ready' ? 1 : 0, 'total_steps' => 1, 'unit' => 'trash_recovery']];
    }
}
