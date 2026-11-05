<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibrary\Data;

use JsonSerializable;

final readonly class CatalogEntry implements JsonSerializable
{
    public function __construct(public string $id, public string $workspaceId, public string $name, public array $tags, public int $version, public array $audio, public array $provenance, public ?string $duplicateOf, public bool $available) {}

    public function jsonSerialize(): array
    {
        return ['id' => $this->id, 'workspace_id' => $this->workspaceId, 'name' => $this->name, 'tags' => $this->tags,
            'version' => $this->version, 'audio' => $this->audio, 'provenance' => $this->provenance,
            'duplicate_of' => $this->duplicateOf, 'available' => $this->available];
    }
}
