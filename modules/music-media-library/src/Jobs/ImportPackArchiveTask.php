<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibrary\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Liberu\Music\MediaLibrary\Services\ArchiveImports;
use LogicException;
use Throwable;

final class ImportPackArchiveTask implements ShouldQueueAfterCommit
{
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    public function __construct(public readonly string $taskId, public readonly string $dispatchId)
    {
        $timeout = config('music-media.archive_import_timeout_seconds', 60);
        if (! is_int($timeout) || $timeout < 60 || $timeout > 3600) {
            throw new LogicException('Archive import timeout must be an integer between 60 and 3600 seconds.');
        }
        $this->timeout = $timeout;
    }

    public function handle(ArchiveImports $imports): void
    {
        $imports->process($this->taskId, $this->dispatchId, $this->timeout);
    }

    public function failed(?Throwable $exception): void
    {
        app(ArchiveImports::class)->failed($this->taskId, $this->dispatchId);
    }
}
