<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibrary\Data;

use JsonSerializable;

final readonly class QuarantineCollection implements JsonSerializable
{
    /** @param list<int> $chunkIds */
    public function __construct(
        public string $workspaceId,
        public string $eligibleBefore,
        public bool $dryRun,
        public array $chunkIds,
        public int $declaredBytes,
        public ?int $nextChunkId,
    ) {}

    /** @return array{schema_version: int, workspace_id: string, eligible_before: string, dry_run: bool, chunk_ids: list<int>, declared_bytes: int, next_chunk_id: ?int} */
    public function jsonSerialize(): array
    {
        return ['schema_version' => 1, 'workspace_id' => $this->workspaceId, 'eligible_before' => $this->eligibleBefore,
            'dry_run' => $this->dryRun, 'chunk_ids' => $this->chunkIds, 'declared_bytes' => $this->declaredBytes, 'next_chunk_id' => $this->nextChunkId];
    }
}
