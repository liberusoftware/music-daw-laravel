<?php

declare(strict_types=1);

namespace Liberu\Music\Projects\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\ConnectionInterface;

interface SessionMediaRetention
{
    /** @param list<string> $mediaIds */
    public function retain(Authenticatable $actor, string $workspaceId, string $projectId, int $version, array $mediaIds, ConnectionInterface $transaction): void;
}
