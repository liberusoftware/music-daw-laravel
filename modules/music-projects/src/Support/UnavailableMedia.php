<?php

declare(strict_types=1);

namespace Liberu\Music\Projects\Support;

use Liberu\Music\Projects\Contracts\VerifiedMedia;
use LogicException;

final class UnavailableMedia implements VerifiedMedia
{
    public function resolve(string $workspaceId, string $mediaId): array
    {
        throw new LogicException('A verified media catalogue must be enabled before sessions can reference media.');
    }
}
