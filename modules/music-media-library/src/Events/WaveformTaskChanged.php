<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibrary\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Liberu\Music\MediaLibrary\Data\WaveformTask;

final readonly class WaveformTaskChanged implements ShouldDispatchAfterCommit
{
    public function __construct(public WaveformTask $task) {}
}
