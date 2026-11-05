<?php

declare(strict_types=1);

namespace Liberu\Music\Projects\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Liberu\Music\Projects\Data\SessionRevision;

final readonly class SessionSaved implements ShouldDispatchAfterCommit
{
    public function __construct(public SessionRevision $revision, public string $actorId) {}
}
