<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibrary\Contracts;

use Closure;
use Liberu\Music\MediaLibrary\Data\MediaPack;

interface ArchiveImportControl
{
    public function checkpoint(int $completedAssets, int $totalAssets): void;

    /** @param Closure(): MediaPack $publish */
    public function publish(Closure $publish): MediaPack;
}
