<?php

namespace App\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Liberu\Foundation\Tenancy\TenantContext;
use Liberu\Music\ProjectsFilament\Contracts\WorkspaceContext;

final class MusicWorkspaceContext implements WorkspaceContext
{
    public function current(Authenticatable $actor): ?string
    {
        $id = TenantContext::currentId() ?? TenantContext::forUser($actor);

        return $id === null ? null : (string) $id;
    }
}
