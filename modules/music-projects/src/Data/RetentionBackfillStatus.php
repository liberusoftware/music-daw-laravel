<?php

declare(strict_types=1);

namespace Liberu\Music\Projects\Data;

use JsonSerializable;

final readonly class RetentionBackfillStatus implements JsonSerializable
{
    public function __construct(public string $workspaceId, public ?int $throughRevisionId, public int $lastRevisionId, public int $processedRevisions) {}

    public function complete(): bool
    {
        return $this->throughRevisionId !== null && $this->lastRevisionId >= $this->throughRevisionId;
    }

    public function jsonSerialize(): array
    {
        return ['schema_version' => 1, 'workspace_id' => $this->workspaceId, 'through_revision_id' => $this->throughRevisionId,
            'last_revision_id' => $this->lastRevisionId, 'processed_revisions' => $this->processedRevisions, 'complete' => $this->complete()];
    }
}
