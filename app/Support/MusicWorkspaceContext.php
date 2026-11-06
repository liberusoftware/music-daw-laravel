<?php

namespace App\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Liberu\Foundation\Organizations\Services\CurrentTeamResolver;
use Liberu\Foundation\Tenancy\TenantContext;
use Liberu\Music\ProjectsFilament\Contracts\WorkspaceContext;

final readonly class MusicWorkspaceContext implements WorkspaceContext
{
    public function __construct(private CurrentTeamResolver $teams) {}

    public function current(Authenticatable $actor): ?string
    {
        $id = TenantContext::currentId() ?? TenantContext::forUser($actor);

        $team = $this->teams->resolve($actor, $id);

        return $team === null ? null : (string) $team->getKey();
    }
}
