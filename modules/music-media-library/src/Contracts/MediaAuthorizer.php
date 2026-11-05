<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibrary\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

interface MediaAuthorizer
{
    public function authorize(Authenticatable $actor, string $workspaceId, string $mediaId, string $operation): void;
}
