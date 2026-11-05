<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibrary\Data;

use JsonSerializable;

final readonly class ArchiveExportTask implements JsonSerializable
{
    public function __construct(public string $id, public string $workspaceId, public string $packId, public int $version, public string $status, public ?ArchiveTransfer $transfer, public ?string $expiresAt) {}

    public function jsonSerialize(): array
    {
        return ['schema_version' => 1, 'id' => $this->id, 'workspace_id' => $this->workspaceId, 'pack_id' => $this->packId,
            'version' => $this->version, 'kind' => 'export_pack_archive', 'status' => $this->status, 'result' => $this->transfer, 'expires_at' => $this->expiresAt,
            'terminal' => $this->status !== 'queued', 'cancellable' => $this->status === 'queued',
            'error_code' => match ($this->status) {
                'failed' => 'archive_export_failed', 'expired' => 'archive_result_expired', default => null
            },
            'progress' => ['completed_steps' => $this->transfer === null ? 0 : 1, 'total_steps' => 1, 'unit' => 'pack_archive_export']];
    }
}
