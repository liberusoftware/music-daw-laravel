<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibrary\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\RecordNotFoundException;
use Liberu\Music\MediaLibrary\Commands\RetainAssets;
use Liberu\Music\MediaLibrary\Contracts\MediaAuthorizer;
use LogicException;

final readonly class MediaRetention
{
    public function __construct(private ConnectionInterface $database, private MediaAuthorizer $access) {}

    public function retain(Authenticatable $actor, RetainAssets $command): int
    {
        return $this->database->transaction(fn (): int => $this->retainInTransaction($actor, $command, $this->database), 3);
    }

    public function retainInTransaction(Authenticatable $actor, RetainAssets $command, ConnectionInterface $transaction): int
    {
        if ($transaction !== $this->database || $transaction->transactionLevel() < 1) {
            throw new LogicException('Retention must share the active revision transaction.');
        }
        $this->access->authorize($actor, $command->workspaceId, '*', 'retain');
        if ($command->assetIds === []) {
            return 0;
        }
        $assets = $this->database->table('music_media_uploads')->where('workspace_id', $command->workspaceId)
            ->whereIn('id', $command->assetIds)->where('status', 'ready')->orderBy('id')->lockForUpdate()->get(['id']);
        if ($assets->count() !== count($command->assetIds)) {
            throw new RecordNotFoundException('Every retained asset must be ready in the requested workspace.');
        }
        $rows = [];
        foreach ($assets as $asset) {
            $this->access->authorize($actor, $command->workspaceId, $asset->id, 'read');
            $rows[] = ['asset_id' => $asset->id, 'workspace_id' => $command->workspaceId,
                'reference_hash' => hash('sha256', $command->kind."\0".$command->referenceId),
                'reference_kind' => $command->kind, 'reference_id' => $command->referenceId,
                'actor_id' => (string) $actor->getAuthIdentifier(), 'created_at' => now()];
        }
        foreach (array_chunk($rows, 500) as $batch) {
            $this->database->table('music_media_retention')->insertOrIgnore($batch);
        }

        return count($rows);
    }

    public function isProtected(Authenticatable $actor, string $workspaceId, string $assetId): bool
    {
        $this->access->authorize($actor, $workspaceId, $assetId, 'read');
        $asset = $this->database->table('music_media_uploads')->where('workspace_id', $workspaceId)->where('id', $assetId)->where('status', 'ready')->firstOrFail(['sha256']);

        // Duplicate catalog entries can share one content-addressed object. A pin
        // on any of them protects those bytes without disclosing reference IDs.
        return $this->database->table('music_media_retention as pins')->join('music_media_uploads as assets', 'assets.id', '=', 'pins.asset_id')
            ->where('pins.workspace_id', $workspaceId)->where('assets.workspace_id', $workspaceId)->where('assets.sha256', $asset->sha256)
            ->where(fn ($query) => $query->whereNull('pins.expires_at')->orWhere('pins.expires_at', '>', now('UTC')))->exists();
    }
}
