<?php

declare(strict_types=1);

namespace Liberu\Music\Projects\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

interface ProjectAccess
{
    public function authorize(Authenticatable $actor, string $workspaceId, string $projectId, string $operation): void;
}
