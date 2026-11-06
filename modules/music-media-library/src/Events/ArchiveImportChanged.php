<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibrary\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Liberu\Music\MediaLibrary\Data\ArchiveImportTask;

final readonly class ArchiveImportChanged implements ShouldDispatchAfterCommit
{
    public function __construct(public ArchiveImportTask $task) {}
}
