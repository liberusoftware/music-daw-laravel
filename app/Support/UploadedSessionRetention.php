<?php

namespace App\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\ConnectionInterface;
use Liberu\Music\MediaLibrary\Commands\RetainAssets;
use Liberu\Music\MediaLibrary\Services\MediaRetention;
use Liberu\Music\Projects\Contracts\SessionMediaRetention;

final readonly class UploadedSessionRetention implements SessionMediaRetention
{
    public function __construct(private MediaRetention $retention) {}

    public function retain(Authenticatable $actor, string $workspaceId, string $projectId, int $version, array $mediaIds, ConnectionInterface $transaction): void
    {
        $this->retention->retainInTransaction($actor, new RetainAssets($workspaceId, $projectId.':'.$version, $mediaIds), $transaction);
    }
}
