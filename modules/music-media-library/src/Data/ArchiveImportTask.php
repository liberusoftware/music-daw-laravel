<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibrary\Data;

use JsonSerializable;

final readonly class ArchiveImportTask implements JsonSerializable
{
    public function __construct(public string $id, public string $workspaceId, public string $inputId, public string $packId, public string $status, public int $completedAssets, public int $totalAssets, public int $attempt, public bool $cancelRequested, public bool $stalled, public ?int $resultVersion) {}

    public function jsonSerialize(): array
    {
        return ['schema_version' => 1, 'id' => $this->id, 'workspace_id' => $this->workspaceId, 'input_id' => $this->inputId, 'pack_id' => $this->packId,
            'kind' => 'import_pack_archive', 'status' => $this->status, 'attempt' => $this->attempt, 'cancel_requested' => $this->cancelRequested,
            'result_version' => $this->resultVersion, 'terminal' => in_array($this->status, ['ready', 'failed', 'cancelled'], true),
            'cancellable' => in_array($this->status, ['queued', 'running'], true) && ! $this->cancelRequested,
            'retryable' => in_array($this->status, ['queued', 'failed'], true) || $this->stalled,
            'error_code' => $this->status === 'failed' ? 'archive_import_failed' : ($this->stalled ? 'archive_import_stalled' : null),
            'progress' => ['completed_steps' => $this->completedAssets, 'total_steps' => $this->totalAssets, 'unit' => 'processed_assets']];
    }
}
