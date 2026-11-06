<?php

declare(strict_types=1);

namespace Liberu\Music\ProjectsFilament\Support;

use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use Liberu\Music\ProjectsFilament\Contracts\WorkspaceContext;

final class FilamentWorkspaceContext implements WorkspaceContext
{
    public function current(Authenticatable $actor): ?string
    {
        $id = Filament::getTenant()?->getKey();

        return $id === null ? null : (string) $id;
    }
}
