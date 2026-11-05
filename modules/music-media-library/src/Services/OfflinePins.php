<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibrary\Services;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\RecordNotFoundException;
use Illuminate\Validation\ValidationException;
use Liberu\Music\MediaLibrary\Commands\RetainAssets;
use Liberu\Music\MediaLibrary\Commands\SaveOfflinePin;
use Liberu\Music\MediaLibrary\Contracts\MediaAuthorizer;
use Liberu\Music\MediaLibrary\Data\OfflinePin;
use Liberu\Music\MediaLibrary\Events\OfflinePinSaved;
use Liberu\Music\MediaLibrary\Exceptions\UploadConflict;

final readonly class OfflinePins
{
    public function __construct(private ConnectionInterface $database, private MediaAuthorizer $access, private MediaRetention $retention, private Dispatcher $events) {}

    public function save(Authenticatable $actor, SaveOfflinePin $command): OfflinePin
    {
        $this->access->authorize($actor, $command->workspaceId, $command->pinId, 'pin');
        $actorId = (string) $actor->getAuthIdentifier();
        $fingerprint = hash('sha256', json_encode([$actorId, $command], JSON_THROW_ON_ERROR));

        return $this->database->transaction(function () use ($actor, $actorId, $command, $fingerprint): OfflinePin {
            $this->database->table('music_media_offline_pins')->insertOrIgnore([
                'id' => $command->pinId, 'workspace_id' => $command->workspaceId, 'actor_id' => $actorId,
                'version' => 0, 'assets' => '[]', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $query = $this->owned($actor, $command->workspaceId, $command->pinId);
            $current = $query->lockForUpdate()->firstOrFail();
            $previous = $this->database->table('music_media_offline_pin_commands')->where('pin_id', $command->pinId)->where('command_hash', hash('sha256', $command->commandKey))->first();
            if ($previous !== null) {
                if (! hash_equals($previous->fingerprint, $fingerprint)) {
                    throw new UploadConflict('The offline pin command key already belongs to another operation.');
                }
                $this->access->authorize($actor, $command->workspaceId, '*', 'retain');
                $pin = $this->fromResult(json_decode($previous->result, true, flags: JSON_THROW_ON_ERROR));
                $this->authorizeAssets($actor, $command->workspaceId, $pin->assetIds);

                return $pin;
            }
            if ((int) $current->version !== $command->expectedVersion) {
                throw new UploadConflict('The offline pin changed; reload it before saving.');
            }
            if ($command->expiresAt !== null && CarbonImmutable::parse($command->expiresAt)->lessThanOrEqualTo(CarbonImmutable::now('UTC'))) {
                throw ValidationException::withMessages(['expires' => 'A new offline pin lease must expire in the future.']);
            }
            $oldIds = json_decode($current->assets, true, flags: JSON_THROW_ON_ERROR);
            $this->authorizeAssets($actor, $command->workspaceId, array_values(array_unique([...$oldIds, ...$command->assetIds])));
            // Actor-qualified identities prevent two users' device pins from
            // sharing a retention reference, even when they choose the same label.
            $reference = 'offline:'.hash('sha256', $actorId).':'.$command->pinId;
            $this->retention->retainInTransaction($actor, new RetainAssets($command->workspaceId, $reference, $command->assetIds, 'offline-pin'), $this->database);
            $pins = $this->database->table('music_media_retention')->where('workspace_id', $command->workspaceId)
                ->where('reference_kind', 'offline-pin')->where('reference_hash', hash('sha256', "offline-pin\0".$reference))->where('actor_id', $actorId);
            (clone $pins)->whereNotIn('asset_id', $command->assetIds)->delete();
            $pins->update(['expires_at' => $command->expiresAt === null ? null : CarbonImmutable::parse($command->expiresAt)->format('Y-m-d H:i:s')]);
            $pin = new OfflinePin($command->workspaceId, $command->pinId, $command->assetIds, $command->expectedVersion + 1, $command->expiresAt);
            $query->update(['version' => $pin->version, 'assets' => json_encode($pin->assetIds, JSON_THROW_ON_ERROR), 'expires_at' => $pin->expiresAt, 'updated_at' => now()]);
            $this->database->table('music_media_offline_pin_commands')->insert(['pin_id' => $pin->pinId,
                'command_hash' => hash('sha256', $command->commandKey), 'fingerprint' => $fingerprint,
                'result' => json_encode($pin, JSON_THROW_ON_ERROR), 'created_at' => now()]);
            $this->events->dispatch(new OfflinePinSaved($pin, $actorId));

            return $pin;
        }, 3);
    }

    public function get(Authenticatable $actor, string $workspaceId, string $pinId): OfflinePin
    {
        $this->access->authorize($actor, $workspaceId, $pinId, 'pin');
        $row = $this->owned($actor, $workspaceId, $pinId)->firstOrFail();
        $ids = json_decode($row->assets, true, flags: JSON_THROW_ON_ERROR);
        $this->authorizeAssets($actor, $workspaceId, $ids);

        return new OfflinePin($workspaceId, $pinId, $ids, (int) $row->version, $row->expires_at);
    }

    private function owned(Authenticatable $actor, string $workspaceId, string $pinId): Builder
    {
        return $this->database->table('music_media_offline_pins')->where('workspace_id', $workspaceId)->where('id', $pinId)->where('actor_id', (string) $actor->getAuthIdentifier());
    }

    private function authorizeAssets(Authenticatable $actor, string $workspaceId, array $ids): void
    {
        $assets = $this->database->table('music_media_uploads')->where('workspace_id', $workspaceId)->whereIn('id', $ids)->where('status', 'ready')->orderBy('id')->get(['id']);
        if ($assets->count() !== count($ids)) {
            throw new RecordNotFoundException('Every offline asset must be ready in the requested workspace.');
        }
        foreach ($assets as $asset) {
            $this->access->authorize($actor, $workspaceId, $asset->id, 'read');
        }
    }

    private function fromResult(array $result): OfflinePin
    {
        return new OfflinePin($result['workspace_id'], $result['pin_id'], $result['asset_ids'], $result['version'], $result['expires_at']);
    }
}
