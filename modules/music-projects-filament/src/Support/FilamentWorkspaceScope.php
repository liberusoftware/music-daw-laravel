<?php

declare(strict_types=1);

namespace Liberu\Music\ProjectsFilament\Support;

use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Liberu\Music\Projects\Contracts\ProjectAccess;
use Liberu\Music\ProjectsFilament\Contracts\WorkspaceContext;
use Liberu\Music\ProjectsLivewire\Contracts\WorkspaceScope;

final readonly class FilamentWorkspaceScope implements WorkspaceScope
{
    public function __construct(private WorkspaceContext $context, private ProjectAccess $access) {}

    public function authorize(Authenticatable $actor, string $workspaceId, string $scope): void
    {
        if ($scope !== 'filament' || Filament::auth()->id() !== $actor->getAuthIdentifier()
            || $this->context->current($actor) !== $workspaceId) {
            throw new AuthorizationException('The current panel workspace has changed.');
        }
        $this->access->authorize($actor, $workspaceId, '*', 'list');
    }
}
