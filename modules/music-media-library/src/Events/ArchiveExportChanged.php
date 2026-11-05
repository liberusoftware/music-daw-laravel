<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibrary\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Liberu\Music\MediaLibrary\Data\ArchiveExportTask;

final readonly class ArchiveExportChanged implements ShouldDispatchAfterCommit
{
    public function __construct(public ArchiveExportTask $task) {}
}
