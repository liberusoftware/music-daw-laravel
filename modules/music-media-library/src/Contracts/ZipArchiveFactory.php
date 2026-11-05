<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibrary\Contracts;

use ZipArchive;

interface ZipArchiveFactory
{
    public function open(string $path, int $flags): ZipArchive;
}
