<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibrary\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Liberu\Music\MediaLibrary\Commands\SearchAssets;
use Liberu\Music\MediaLibrary\Commands\UpdateAssetMetadata;
use Liberu\Music\MediaLibrary\Contracts\MediaAuthorizer;
use Liberu\Music\MediaLibrary\Data\AssetMetadata;
use Liberu\Music\MediaLibrary\Data\CatalogEntry;
use Liberu\Music\MediaLibrary\Events\AssetMetadataUpdated;
use Liberu\Music\MediaLibrary\Exceptions\MetadataConflict;

final readonly class MediaCatalog
{
    public function __construct(private ConnectionInterface $database, private MediaUploads $uploads, private MediaAuthorizer $access, private Filesystem $objects, private Dispatcher $events) {}

    public function update(Authenticatable $actor, UpdateAssetMetadata $command): AssetMetadata
    {
        $this->access->authorize($actor, $command->workspaceId, $command->assetId, 'annotate');
        $asset = $this->uploads->get($actor, $command->workspaceId, $command->assetId);
        $fingerprint = hash('sha256', json_encode([(string) $actor->getAuthIdentifier(), $command], JSON_THROW_ON_ERROR));

        return $this->database->transaction(function () use ($actor, $command, $asset, $fingerprint): AssetMetadata {
            $this->database->table('music_media_annotations')->insertOrIgnore(['asset_id' => $asset->id, 'name' => $asset->name, 'search_name' => Str::lower($asset->name), 'version' => 0, 'tags' => '[]']);
            $row = $this->database->table('music_media_annotations')->where('asset_id', $asset->id)->lockForUpdate()->firstOrFail();
            $replay = $this->database->table('music_media_annotation_commands')->where('asset_id', $asset->id)->where('command_key', $command->commandKey)->first();
            if ($replay !== null) {
                if (! hash_equals($fingerprint, $replay->fingerprint)) {
                    throw new MetadataConflict('The command key already belongs to another metadata edit.');
                }
                $result = json_decode($replay->result, true, flags: JSON_THROW_ON_ERROR);

                return new AssetMetadata($asset->id, $result['name'], $result['tags'], $result['version']);
            }
            if ((int) $row->version !== $command->expectedVersion) {
                throw new MetadataConflict('The asset metadata changed; reload it before editing.');
            }
            $result = new AssetMetadata($asset->id, $command->name, $command->tags, $command->expectedVersion + 1);
            $changed = $this->database->table('music_media_annotations')->where('asset_id', $asset->id)->where('version', $command->expectedVersion)
                ->update(['name' => $result->name, 'search_name' => Str::lower($result->name), 'tags' => json_encode($result->tags, JSON_THROW_ON_ERROR), 'version' => $result->version]);
            if ($changed !== 1) {
                throw new MetadataConflict('The asset metadata changed during this edit.');
            }
            $this->database->table('music_media_tags')->where('asset_id', $asset->id)->delete();
            if ($result->tags !== []) {
                $this->database->table('music_media_tags')->insert(array_map(fn (string $tag): array => [
                    'asset_id' => $asset->id, 'tag' => $tag, 'tag_hash' => hash('sha256', $tag),
                ], $result->tags));
            }
            $this->database->table('music_media_annotation_commands')->insert(['asset_id' => $asset->id, 'command_key' => $command->commandKey,
                'fingerprint' => $fingerprint, 'result' => json_encode($result, JSON_THROW_ON_ERROR)]);
            $this->events->dispatch(new AssetMetadataUpdated($result, $command->workspaceId, (string) $actor->getAuthIdentifier()));

            return $result;
        }, 3);
    }

    /** @return LengthAwarePaginator<int, CatalogEntry> */
    public function search(Authenticatable $actor, SearchAssets $search): LengthAwarePaginator
    {
        $this->access->authorize($actor, $search->workspaceId, '*', 'list');
        $query = $this->database->table('music_media_uploads as assets')->leftJoin('music_media_annotations as annotations', 'annotations.asset_id', '=', 'assets.id')
            ->where('assets.workspace_id', $search->workspaceId)->where('assets.status', 'ready');
        if ($search->query !== '') {
            $literal = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], Str::lower($search->query));
            $query->whereRaw("COALESCE(annotations.search_name, assets.search_name) LIKE ? ESCAPE '!'", ['%'.$literal.'%']);
        }
        if ($search->sha256 !== null) {
            $query->where('assets.sha256', $search->sha256);
        }
        foreach ($search->tags as $tag) {
            $query->whereExists(function ($tags) use ($tag): void {
                $tags->selectRaw('1')->from('music_media_tags')->whereColumn('music_media_tags.asset_id', 'assets.id')->where('tag_hash', hash('sha256', $tag));
            });
        }
        $total = (clone $query)->count();
        $rows = $query->select(['assets.id', 'assets.name as original_name', 'assets.metadata', 'assets.provenance', 'assets.duplicate_of', 'assets.blob_key', 'annotations.name', 'annotations.tags', 'annotations.version'])
            ->orderByDesc('assets.created_at')->orderByDesc('assets.id')->offset(($search->page - 1) * $search->perPage)->limit($search->perPage)->get();
        $items = [];
        foreach ($rows as $row) {
            $this->access->authorize($actor, $search->workspaceId, $row->id, 'read');
            $items[] = new CatalogEntry($row->id, $search->workspaceId, $row->name ?? $row->original_name,
                json_decode($row->tags ?? '[]', true, flags: JSON_THROW_ON_ERROR), (int) ($row->version ?? 0),
                json_decode($row->metadata, true, flags: JSON_THROW_ON_ERROR), json_decode($row->provenance, true, flags: JSON_THROW_ON_ERROR),
                $row->duplicate_of, $this->objects->exists($row->blob_key));
        }

        return new LengthAwarePaginator($items, $total, $search->perPage, $search->page);
    }
}
