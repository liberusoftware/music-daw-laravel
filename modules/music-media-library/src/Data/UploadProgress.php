<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibrary\Data;

final readonly class UploadProgress
{
    public function __construct(public string $id, public string $workspaceId, public string $status, public int $receivedBytes, public int $totalBytes) {}
}
