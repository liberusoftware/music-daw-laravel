<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibrary\Data;

use JsonSerializable;

final readonly class TrashedAsset implements JsonSerializable
{
    public function __construct(public string $id, public string $name, public string $status, public string $trashedAt, public string $purgeAfter) {}

    public function jsonSerialize(): array
    {
        return ['schema_version' => 1, 'id' => $this->id, 'name' => $this->name, 'status' => $this->status, 'trashed_at' => $this->trashedAt, 'purge_after' => $this->purgeAfter];
    }
}
