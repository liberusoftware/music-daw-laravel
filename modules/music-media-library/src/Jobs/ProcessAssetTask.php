<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibrary\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Liberu\Music\MediaLibrary\Services\AssetProcessing;
use Throwable;

final class ProcessAssetTask implements ShouldQueueAfterCommit
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public array $backoff = [5, 30, 120];

    public function __construct(public readonly string $taskId) {}

    public function handle(AssetProcessing $processing): void
    {
        $processing->process($this->taskId);
    }

    public function failed(?Throwable $exception): void
    {
        app(AssetProcessing::class)->failed($this->taskId);
    }
}
