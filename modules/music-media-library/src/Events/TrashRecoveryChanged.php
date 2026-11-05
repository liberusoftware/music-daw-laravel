<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibrary\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Liberu\Music\MediaLibrary\Data\TrashRecoveryTask;

final readonly class TrashRecoveryChanged implements ShouldDispatchAfterCommit
{
    public function __construct(public TrashRecoveryTask $task) {}
}
