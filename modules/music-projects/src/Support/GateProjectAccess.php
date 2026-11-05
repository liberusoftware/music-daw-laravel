<?php

declare(strict_types=1);

namespace Liberu\Music\Projects\Support;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Liberu\Music\Projects\Contracts\ProjectAccess;

final readonly class GateProjectAccess implements ProjectAccess
{
    public function __construct(private Gate $gate) {}

    public function authorize(Authenticatable $actor, string $workspaceId, string $projectId, string $operation): void
    {
        $this->gate->forUser($actor)->authorize('music.projects.'.$operation, [$workspaceId, $projectId]);
    }
}
