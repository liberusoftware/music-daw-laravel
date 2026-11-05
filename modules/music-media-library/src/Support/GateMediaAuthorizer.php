<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibrary\Support;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Liberu\Music\MediaLibrary\Contracts\MediaAuthorizer;

final readonly class GateMediaAuthorizer implements MediaAuthorizer
{
    public function __construct(private Gate $gate) {}

    public function authorize(Authenticatable $actor, string $workspaceId, string $mediaId, string $operation): void
    {
        $this->gate->forUser($actor)->authorize('music.media.'.$operation, [$workspaceId, $mediaId]);
    }
}
