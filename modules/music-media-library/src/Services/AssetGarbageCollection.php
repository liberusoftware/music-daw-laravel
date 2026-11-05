<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibrary\Services;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Liberu\Music\MediaLibrary\Commands\CollectTrashedAssets;
use Liberu\Music\MediaLibrary\Contracts\MediaAuthorizer;
use Liberu\Music\MediaLibrary\Data\AssetCollection;
use Liberu\Music\MediaLibrary\Data\AssetLifecycleChange;
use Liberu\Music\MediaLibrary\Events\AssetLifecycleChanged;
use Liberu\Music\MediaLibrary\Exceptions\UploadConflict;
use LogicException;
use RuntimeException;

final readonly class AssetGarbageCollection
{
    public function __construct(private ConnectionInterface $database, private Filesystem $objects, private MediaAuthorizer $access, private ContentLocks $locks, private UploadBudgets $budgets, private Dispatcher $events) {}

    public function collect(Authenticatable $actor, CollectTrashedAssets $command): AssetCollection
    {
        $this->access->authorize($actor, $command->workspaceId, '*', 'collect');
        if ($this->database->transactionLevel() !== 0) {
            throw new LogicException('Asset collection requires its own committed deletion intents; do not wrap it in another transaction.');
        }
        $report = $this->database->transaction(function () use ($actor, $command): AssetCollection {
            $query = $this->database->table('music_media_uploads')->where('workspace_id', $command->workspaceId)
                ->where(fn ($query) => $query->where('status', 'purging')->orWhere(fn ($query) => $query->where('status', 'trashed')->where('purge_after', '<=', CarbonImmutable::now('UTC')->format('Y-m-d H:i:s'))))
                ->when($command->afterAssetId !== null, fn ($query) => $query->where('id', '>', $command->afterAssetId))
                ->orderBy('id')->limit($command->limit + 1);
            if (! $command->dryRun) {
                $query->lockForUpdate();
            }
            $rows = $query->get(['id', 'status', 'total_bytes']);
            $more = $rows->count() > $command->limit;
            $rows = $rows->take($command->limit);
            $ids = $rows->pluck('id')->all();
            foreach ($ids as $id) {
                $this->access->authorize($actor, $command->workspaceId, $id, 'collect');
            }
            $protected = $this->database->table('music_media_retention')->where('workspace_id', $command->workspaceId)->whereIn('asset_id', $ids)
                ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now('UTC')))->exists();
            if ($protected) {
                throw new UploadConflict('Collection cannot remove an asset with an active retention reference.');
            }
            if (! $command->dryRun && $ids !== []) {
                $changed = $rows->where('status', 'trashed')->pluck('id')->all();
                if ($changed !== []) {
                    $updated = $this->database->table('music_media_uploads')->whereIn('id', $changed)->update(['status' => 'purging', 'updated_at' => now('UTC')]);
                    if ($updated !== count($changed)) {
                        throw new LogicException('The deletion intents were not acknowledged.');
                    }
                }
                if ($changed !== []) {
                    $this->events->dispatch(new AssetLifecycleChanged(new AssetLifecycleChange($command->workspaceId, $changed, 'purging', CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s\Z'), null), (string) $actor->getAuthIdentifier()));
                }
            }

            return new AssetCollection($command->workspaceId, $command->dryRun, $ids, (int) $rows->sum('total_bytes'), $more ? $ids[count($ids) - 1] : null);
        }, 3);
        // Intent is already committed. A failure below leaves only unreadable,
        // unretainable tombstones, so partial deletion is safe to acknowledge again.
        if (! $command->dryRun) {
            foreach ($report->assetIds as $id) {
                $this->purge($actor, $command->workspaceId, $id);
            }
        }

        return $report;
    }

    private function purge(Authenticatable $actor, string $workspaceId, string $assetId): void
    {
        $this->database->transaction(function () use ($actor, $workspaceId, $assetId): void {
            $row = $this->database->table('music_media_uploads')->where('workspace_id', $workspaceId)->where('id', $assetId)->lockForUpdate()->firstOrFail();
            if ($row->status === 'collected') {
                return;
            }
            $this->access->authorize($actor, $workspaceId, $assetId, 'collect');
            $this->locks->acquire($workspaceId, $row->sha256, $this->database);
            $key = 'music/assets/'.hash('sha256', $workspaceId).'/'.$row->sha256;
            if ($row->status !== 'purging' || ! Str::isUuid($assetId) || ! preg_match('/^[a-f0-9]{64}$/D', $row->sha256) || ! hash_equals($key, $row->blob_key)) {
                throw new LogicException('The asset deletion intent contains an invalid content address.');
            }
            $chunks = $this->database->table('music_media_upload_chunks')->where('upload_id', $assetId)->orderBy('id')->limit(4097)->get();
            if ($chunks->count() > 4096) {
                throw new LogicException('The asset chunk ledger exceeds its supported bound.');
            }
            foreach ($chunks as $chunk) {
                $expected = 'music/quarantine/'.$assetId.'/'.$chunk->offset_bytes.'-'.$chunk->sha256;
                if (! ctype_digit((string) $chunk->offset_bytes) || ! preg_match('/^[a-f0-9]{64}$/D', $chunk->sha256) || ! hash_equals($expected, $chunk->object_key)) {
                    throw new LogicException('The asset chunk ledger contains an invalid object address.');
                }
            }
            foreach ($chunks as $chunk) {
                $this->remove($chunk->object_key);
            }
            $liveAlias = $this->database->table('music_media_uploads')->where('workspace_id', $workspaceId)->where('sha256', $row->sha256)->whereIn('status', ['ready', 'trashed'])->exists();
            if (! $liveAlias) {
                $this->remove($key);
            }
            $this->database->table('music_media_upload_chunks')->where('upload_id', $assetId)->delete();
            $updated = $this->database->table('music_media_uploads')->where('id', $assetId)->update(['status' => 'collected', 'updated_at' => now('UTC')]);
            if ($updated !== 1) {
                throw new LogicException('The collection acknowledgement was not saved.');
            }
            $this->budgets->releaseCollectedInTransaction($actor, $workspaceId, [$assetId], $this->database);
            $this->events->dispatch(new AssetLifecycleChanged(new AssetLifecycleChange($workspaceId, [$assetId], 'collected', CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s\Z'), null), (string) $actor->getAuthIdentifier()));
        }, 3);
    }

    private function remove(string $key): void
    {
        if ($this->objects->exists($key)) {
            $this->removeExisting($key);
        }
    }

    private function removeExisting(string $key): void
    {
        if (! $this->objects->delete($key) || $this->objects->exists($key)) {
            throw new RuntimeException('The trashed audio object could not be removed.');
        }
    }
}
