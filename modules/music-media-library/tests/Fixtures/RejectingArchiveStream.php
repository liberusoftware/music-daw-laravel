<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibrary\Tests\Fixtures;

class RejectingArchiveStream
{
    public mixed $context;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return true;
    }

    public function stream_write(string $bytes): int
    {
        return 0;
    }

    public function stream_eof(): bool
    {
        return true;
    }

    public function stream_stat(): array
    {
        return [];
    }
}
