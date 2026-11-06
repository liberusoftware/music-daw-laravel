<?php

declare(strict_types=1);

namespace Liberu\Music\Projects\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\ConnectionInterface;
use Liberu\Music\Projects\Data\SessionRevision;

/** A configured sink must use the supplied active transaction or fail before commit. */
interface SessionAudit
{
    public function record(Authenticatable $actor, SessionRevision $revision, string $operation, ?int $sourceVersion, ConnectionInterface $transaction): void;
}
