<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibrary\Data;

use JsonSerializable;

final readonly class UploadBudget implements JsonSerializable
{
    public function __construct(public string $workspaceId, public int $reservedBytes, public ?int $limitBytes) {}

    public function availableBytes(): ?int
    {
        return $this->limitBytes === null ? null : max(0, $this->limitBytes - $this->reservedBytes);
    }

    public function overLimit(): bool
    {
        return $this->limitBytes !== null && $this->reservedBytes > $this->limitBytes;
    }

    public function jsonSerialize(): array
    {
        return ['schema_version' => 1, 'workspace_id' => $this->workspaceId, 'reserved_bytes' => $this->reservedBytes,
            'limit_bytes' => $this->limitBytes, 'available_bytes' => $this->availableBytes(), 'over_limit' => $this->overLimit(), 'reservation_multiplier' => 2];
    }
}
