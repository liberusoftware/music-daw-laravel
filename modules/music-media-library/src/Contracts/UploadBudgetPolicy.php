<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibrary\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

interface UploadBudgetPolicy
{
    public function maximumReservedBytes(Authenticatable $actor, string $workspaceId): ?int;
}
