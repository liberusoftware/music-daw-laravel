<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Liberu\Foundation\Organizations\Services\CurrentTeamResolver;
use Liberu\Music\MediaLibrary\Contracts\MediaAuthorizer;
use Spatie\Permission\PermissionRegistrar;

final readonly class WorkspaceMediaAuthorizer implements MediaAuthorizer
{
    public function __construct(private CurrentTeamResolver $teams, private PermissionRegistrar $permissions) {}

    public function authorize(Authenticatable $actor, string $workspaceId, string $mediaId, string $operation): void
    {
        $workspace = filter_var($workspaceId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (! config('permission.teams') || ! $actor instanceof User || $workspace === false || (string) $workspace !== $workspaceId
            || $this->teams->resolve($actor, $workspace) === null) {
            throw new AuthorizationException('Active workspace membership is required.');
        }

        // Never reuse relations loaded for another workspace or trust the
        // ambient HTTP/worker permission scope. Restore it even on denial.
        $previous = $this->permissions->getPermissionsTeamId();
        $this->permissions->setPermissionsTeamId($workspace);
        try {
            $identity = $actor->withoutRelations();
            if (! $identity->isSuperAdmin() && ! $identity->checkPermissionTo('music.media.'.$operation, 'web')) {
                throw new AuthorizationException('The workspace does not grant this Music operation.');
            }
        } finally {
            $this->permissions->setPermissionsTeamId($previous);
        }
    }
}
