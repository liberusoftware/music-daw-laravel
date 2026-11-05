<?php

declare(strict_types=1);

namespace Liberu\Music\Projects\Data;

final readonly class SessionMetadataPage
{
    /** @param list<array{workspace_id: string, project_id: string, title: string, version: int, checksum: string, autosave: bool, template: bool, created_at: string}> $items */
    public function __construct(public array $items, public ?int $nextCursor) {}
}
