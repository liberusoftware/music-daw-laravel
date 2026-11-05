<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibrary\Services;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\RecordNotFoundException;
use Liberu\Music\MediaLibrary\Commands\ListTrashedAssets;
use Liberu\Music\MediaLibrary\Commands\RecoverTrashedAsset;
use Liberu\Music\MediaLibrary\Commands\TrashAssets;
use Liberu\Music\MediaLibrary\Contracts\MediaAuthorizer;
use Liberu\Music\MediaLibrary\Data\AssetLifecycleChange;
use Liberu\Music\MediaLibrary\Data\TrashedAsset;
use Liberu\Music\MediaLibrary\Data\TrashPage;
use Liberu\Music\MediaLibrary\Events\AssetLifecycleChanged;
use Liberu\Music\MediaLibrary\Exceptions\InvalidAudio;
use Liberu\Music\MediaLibrary\Exceptions\UploadConflict;
use LogicException;

final readonly class AssetLifecycle
{
    public function __construct(private ConnectionInterface $database, private Filesystem $objects, private MediaAuthorizer $access, private ContentLocks $locks, private Dispatcher $events, private WaveInspector $inspector) {}

    public function trash(Authenticatable $actor, TrashAssets $command): AssetLifecycleChange
    {
        foreach ($command->assetIds as $id) {
            $this->access->authorize($actor, $command->workspaceId, $id, 'trash');
            $this->access->authorize($actor, $command->workspaceId, $id, 'read');
        }
        $fingerprint = hash('sha256', json_encode(['trash', (string) $actor->getAuthIdentifier(), $command], JSON_THROW_ON_ERROR));

        return $this->database->transaction(function () use ($actor, $command, $fingerprint): AssetLifecycleChange {
            $replay = $this->replay($command->workspaceId, $command->commandKey, $fingerprint);
            if ($replay !== null) {
                return $replay;
            }
            $rows = $this->database->table('music_media_uploads')->where('workspace_id', $command->workspaceId)->whereIn('id', $command->assetIds)
                ->where('status', 'ready')->orderBy('id')->lockForUpdate()->get(['id']);
            if ($rows->count() !== count($command->assetIds)) {
                throw new RecordNotFoundException('Every trashed asset must be ready in the requested workspace.');
            }
            $protected = $this->database->table('music_media_retention')->where('workspace_id', $command->workspaceId)->whereIn('asset_id', $command->assetIds)
                ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now('UTC')))->exists();
            if ($protected) {
                throw new UploadConflict('An asset is protected by a revision, pack or active offline pin.');
            }
            $now = CarbonImmutable::now('UTC');
            $purge = $now->addHours($command->graceHours);
            $updated = $this->database->table('music_media_uploads')->whereIn('id', $command->assetIds)->update([
                'status' => 'trashed', 'trashed_at' => $now->format('Y-m-d H:i:s'), 'purge_after' => $purge->format('Y-m-d H:i:s'), 'updated_at' => $now,
            ]);
            if ($updated !== count($command->assetIds)) {
                throw new LogicException('The trash transition was not acknowledged.');
            }
            $change = new AssetLifecycleChange($command->workspaceId, $command->assetIds, 'trashed', $now->format('Y-m-d\TH:i:s\Z'), $purge->format('Y-m-d\TH:i:s\Z'));
            $this->remember($command->commandKey, $fingerprint, $change, $actor);

            return $change;
        }, 3);
    }

    public function recover(Authenticatable $actor, RecoverTrashedAsset $command): AssetLifecycleChange
    {
        $this->access->authorize($actor, $command->workspaceId, $command->assetId, 'restore');
        $this->access->authorize($actor, $command->workspaceId, $command->assetId, 'read');
        $fingerprint = hash('sha256', json_encode(['recover', (string) $actor->getAuthIdentifier(), $command], JSON_THROW_ON_ERROR));

        return $this->database->transaction(function () use ($actor, $command, $fingerprint): AssetLifecycleChange {
            $replay = $this->replay($command->workspaceId, $command->commandKey, $fingerprint);
            if ($replay !== null) {
                return $replay;
            }
            $row = $this->database->table('music_media_uploads')->where('workspace_id', $command->workspaceId)->where('id', $command->assetId)
                ->where('status', 'trashed')->lockForUpdate()->firstOrFail();
            $this->locks->acquire($command->workspaceId, $row->sha256, $this->database);
            $key = 'music/assets/'.hash('sha256', $command->workspaceId).'/'.$row->sha256;
            if (! hash_equals($key, $row->blob_key) || ! $this->objects->exists($key)) {
                throw new InvalidAudio('The trashed audio is unavailable; it cannot be recovered from trash.');
            }
            $stream = $this->objects->readStream($key);
            if (! is_resource($stream)) {
                throw new InvalidAudio('The trashed audio could not be opened for recovery.');
            }
            try {
                $audio = $this->inspector->inspect($stream);
                if (! hash_equals($row->sha256, $audio->sha256) || (int) $row->total_bytes !== $audio->bytes) {
                    throw new InvalidAudio('The trashed audio differs from its original verified content.');
                }
            } finally {
                fclose($stream);
            }
            $updated = $this->database->table('music_media_uploads')->where('id', $row->id)->update(['status' => 'ready', 'trashed_at' => null, 'purge_after' => null, 'updated_at' => now('UTC')]);
            if ($updated !== 1) {
                throw new LogicException('The recovery transition was not acknowledged.');
            }
            $change = new AssetLifecycleChange($command->workspaceId, [$row->id], 'ready', CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s\Z'), null);
            $this->remember($command->commandKey, $fingerprint, $change, $actor);

            return $change;
        }, 3);
    }

    public function list(Authenticatable $actor, ListTrashedAssets $query): TrashPage
    {
        $this->access->authorize($actor, $query->workspaceId, '*', 'trash_list');
        $rows = $this->database->table('music_media_uploads as assets')
            ->leftJoin('music_media_annotations as annotations', 'annotations.asset_id', '=', 'assets.id')
            ->where('assets.workspace_id', $query->workspaceId)->whereIn('assets.status', ['trashed', 'purging'])
            ->when($query->afterAssetId !== null, fn ($builder) => $builder->where('assets.id', '>', $query->afterAssetId))
            ->orderBy('assets.id')->limit($query->limit + 1)->get(['assets.id', 'assets.name', 'annotations.name as edited_name', 'assets.status', 'assets.trashed_at', 'assets.purge_after']);
        $more = $rows->count() > $query->limit;
        $items = [];
        foreach ($rows->take($query->limit) as $row) {
            $this->access->authorize($actor, $query->workspaceId, $row->id, 'trash');
            $this->access->authorize($actor, $query->workspaceId, $row->id, 'read');
            $items[] = new TrashedAsset($row->id, $row->edited_name ?? $row->name, $row->status,
                CarbonImmutable::parse($row->trashed_at, 'UTC')->format('Y-m-d\TH:i:s\Z'), CarbonImmutable::parse($row->purge_after, 'UTC')->format('Y-m-d\TH:i:s\Z'));
        }

        return new TrashPage($query->workspaceId, $items, $more ? $items[count($items) - 1]->id : null);
    }

    private function replay(string $workspaceId, string $key, string $fingerprint): ?AssetLifecycleChange
    {
        $this->database->table('music_media_lifecycle_commands')->insertOrIgnore(['workspace_id' => $workspaceId, 'command_hash' => hash('sha256', $key), 'fingerprint' => $fingerprint, 'result' => null]);
        $row = $this->database->table('music_media_lifecycle_commands')->where('workspace_id', $workspaceId)->where('command_hash', hash('sha256', $key))->lockForUpdate()->firstOrFail();
        if (! hash_equals($row->fingerprint, $fingerprint)) {
            throw new UploadConflict('This lifecycle command key belongs to another operation.');
        }
        if ($row->result === null) {
            return null;
        }
        $data = json_decode($row->result, true, flags: JSON_THROW_ON_ERROR);

        return new AssetLifecycleChange($data['workspace_id'], $data['asset_ids'], $data['status'], $data['changed_at'], $data['purge_after']);
    }

    private function remember(string $key, string $fingerprint, AssetLifecycleChange $change, Authenticatable $actor): void
    {
        $updated = $this->database->table('music_media_lifecycle_commands')->where('workspace_id', $change->workspaceId)->where('command_hash', hash('sha256', $key))->where('fingerprint', $fingerprint)
            ->update(['result' => json_encode($change, JSON_THROW_ON_ERROR)]);
        if ($updated !== 1) {
            throw new LogicException('The lifecycle command receipt could not be saved.');
        }
        $this->events->dispatch(new AssetLifecycleChanged($change, (string) $actor->getAuthIdentifier()));
    }
}
