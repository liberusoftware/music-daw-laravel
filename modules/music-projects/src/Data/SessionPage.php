<?php

declare(strict_types=1);

namespace Liberu\Music\Projects\Data;

final readonly class SessionPage
{
    /** @param list<SessionRevision> $items */
    public function __construct(public array $items, public ?int $nextCursor) {}
}
