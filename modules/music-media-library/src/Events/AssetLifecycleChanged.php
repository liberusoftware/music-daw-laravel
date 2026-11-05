<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibrary\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Liberu\Music\MediaLibrary\Data\AssetLifecycleChange;

final readonly class AssetLifecycleChanged implements ShouldDispatchAfterCommit
{
    public function __construct(public AssetLifecycleChange $change, public string $actorId) {}
}
