<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibrary\Support;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Liberu\Music\MediaLibrary\Contracts\ArchiveImportControl;
use Liberu\Music\MediaLibrary\Data\MediaPack;
use Liberu\Music\MediaLibrary\Services\ArchiveImports;

final readonly class QueuedArchiveImportControl implements ArchiveImportControl
{
    public function __construct(private ArchiveImports $imports, private Authenticatable $actor, private string $taskId, private string $nonce, private int $timeoutSeconds) {}

    public function checkpoint(int $completedAssets, int $totalAssets): void
    {
        $this->imports->checkpoint($this->taskId, $this->nonce, $completedAssets, $totalAssets, $this->timeoutSeconds);
    }

    public function publish(Closure $publish): MediaPack
    {
        return $this->imports->publish($this->actor, $this->taskId, $this->nonce, $publish);
    }
}
