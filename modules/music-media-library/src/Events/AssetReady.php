<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibrary\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Liberu\Music\MediaLibrary\Data\ReadyAsset;

final readonly class AssetReady implements ShouldDispatchAfterCommit
{
    public function __construct(public ReadyAsset $asset, public string $actorId) {}
}
