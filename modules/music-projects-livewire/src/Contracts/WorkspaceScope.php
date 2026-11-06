<?php

declare(strict_types=1);

namespace Liberu\Music\ProjectsLivewire\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/** Optional embedding boundary, checked in addition to Projects Core authorization. */
interface WorkspaceScope
{
    public function authorize(Authenticatable $actor, string $workspaceId, string $scope): void;
}
