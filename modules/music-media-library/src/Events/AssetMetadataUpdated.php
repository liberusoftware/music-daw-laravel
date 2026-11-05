<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibrary\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Liberu\Music\MediaLibrary\Data\AssetMetadata;

final readonly class AssetMetadataUpdated implements ShouldDispatchAfterCommit
{
    public function __construct(public AssetMetadata $metadata, public string $workspaceId, public string $actorId) {}
}
