<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibrary\Data;

use JsonSerializable;

final readonly class AssetTask implements JsonSerializable
{
    public function __construct(public string $id, public string $workspaceId, public string $uploadId, public ?string $restoredAssetId, public string $status) {}

    public function kind(): string
    {
        return $this->restoredAssetId === null ? 'verify_upload' : 'restore_asset';
    }

    public function assetId(): string
    {
        return $this->restoredAssetId ?? $this->uploadId;
    }

    public function jsonSerialize(): array
    {
        return ['schema_version' => 1, 'id' => $this->id, 'workspace_id' => $this->workspaceId, 'upload_id' => $this->uploadId,
            'asset_id' => $this->assetId(), 'kind' => $this->kind(), 'status' => $this->status,
            'error_code' => $this->status === 'failed' ? 'asset_processing_failed' : null,
            'terminal' => $this->status !== 'queued', 'cancellable' => $this->status === 'queued',
            'progress' => ['completed_steps' => $this->status === 'ready' ? 1 : 0, 'total_steps' => 1,
                'unit' => $this->restoredAssetId === null ? 'asset_verification' : 'asset_restoration']];
    }
}
