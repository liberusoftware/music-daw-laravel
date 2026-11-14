<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibrary\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Liberu\Music\MediaLibrary\Services\WaveformProcessing;
use Throwable;

final class BuildWaveform implements ShouldQueueAfterCommit
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public array $backoff = [5, 30, 120];

    /** Legacy payloads have no dispatch identity and must be re-requested. */
    public function __construct(public readonly string $taskId, public readonly ?string $dispatchId = null) {}

    public function handle(WaveformProcessing $processing): void
    {
        if (isset($this->dispatchId)) {
            $processing->process($this->taskId, $this->dispatchId);
        }
    }

    public function failed(?Throwable $exception): void
    {
        if (isset($this->dispatchId)) {
            app(WaveformProcessing::class)->failed($this->taskId, $this->dispatchId);
        }
    }
}
