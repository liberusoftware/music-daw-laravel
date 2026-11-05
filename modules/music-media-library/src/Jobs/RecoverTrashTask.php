<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibrary\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Liberu\Music\MediaLibrary\Services\TrashRecovery;
use Throwable;

final class RecoverTrashTask implements ShouldQueueAfterCommit
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public array $backoff = [5, 30, 120];

    public function __construct(public readonly string $taskId) {}

    public function handle(TrashRecovery $processing): void
    {
        $processing->process($this->taskId);
    }

    public function failed(?Throwable $exception): void
    {
        app(TrashRecovery::class)->failed($this->taskId);
    }
}
