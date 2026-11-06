<?php

namespace App\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Liberu\Music\MediaLibrary\Contracts\MediaAuthorizer;
use Liberu\Music\MediaLibrary\Contracts\PackDistributionPolicy;
use Liberu\Music\MediaLibrary\Data\MediaPack;

final readonly class WorkspacePackDistribution implements PackDistributionPolicy
{
    public function __construct(private MediaAuthorizer $access) {}

    public function authorize(Authenticatable $actor, MediaPack $pack): void
    {
        $this->access->authorize($actor, $pack->workspaceId, $pack->packId, 'pack_distribute');
    }
}
