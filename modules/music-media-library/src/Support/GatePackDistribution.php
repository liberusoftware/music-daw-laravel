<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibrary\Support;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Liberu\Music\MediaLibrary\Contracts\PackDistributionPolicy;
use Liberu\Music\MediaLibrary\Data\MediaPack;

final readonly class GatePackDistribution implements PackDistributionPolicy
{
    public function __construct(private Gate $gate) {}

    public function authorize(Authenticatable $actor, MediaPack $pack): void
    {
        $this->gate->forUser($actor)->authorize('music.media.pack_distribute', [$pack]);
    }
}
