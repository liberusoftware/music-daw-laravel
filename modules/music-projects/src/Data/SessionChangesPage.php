<?php

declare(strict_types=1);

namespace Liberu\Music\Projects\Data;

use JsonSerializable;

final readonly class SessionChangesPage implements JsonSerializable
{
    /** @param list<array{version: int, autosave: bool, template: bool, checksum: string, created_at: string}> $changes */
    public function __construct(public array $changes, public int $nextCursor, public int $latestVersion, public bool $hasMore, public bool $resyncRequired) {}

    public function jsonSerialize(): array
    {
        return ['schema_version' => 1, 'changes' => $this->changes, 'next_cursor' => $this->nextCursor,
            'latest_version' => $this->latestVersion, 'has_more' => $this->hasMore, 'resync_required' => $this->resyncRequired];
    }
}
