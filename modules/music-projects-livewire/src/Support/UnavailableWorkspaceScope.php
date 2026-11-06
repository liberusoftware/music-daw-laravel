<?php

declare(strict_types=1);

namespace Liberu\Music\ProjectsLivewire\Support;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Liberu\Music\ProjectsLivewire\Contracts\WorkspaceScope;

final class UnavailableWorkspaceScope implements WorkspaceScope
{
    public function authorize(Authenticatable $actor, string $workspaceId, string $scope): void
    {
        throw new AuthorizationException('The workspace embedding scope is unavailable.');
    }
}
