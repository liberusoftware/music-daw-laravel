<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibrary\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Liberu\Music\MediaLibrary\Data\AssetTask;

final readonly class AssetTaskChanged implements ShouldDispatchAfterCommit
{
    public function __construct(public AssetTask $task) {}
}
