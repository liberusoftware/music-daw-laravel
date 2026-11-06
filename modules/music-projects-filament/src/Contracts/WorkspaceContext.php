<?php

declare(strict_types=1);

namespace Liberu\Music\ProjectsFilament\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

interface WorkspaceContext
{
    /** Return the current server-selected workspace; null denies access. */
    public function current(Authenticatable $actor): ?string;
}
