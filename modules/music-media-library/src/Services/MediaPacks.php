<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibrary\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\RecordNotFoundException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Liberu\Foundation\ImportExport\Contracts\TransferAuthorizer;
use Liberu\Music\MediaLibrary\Commands\RetainAssets;
use Liberu\Music\MediaLibrary\Commands\SaveMediaPack;
use Liberu\Music\MediaLibrary\Contracts\MediaAuthorizer;
use Liberu\Music\MediaLibrary\Contracts\PackDistributionPolicy;
use Liberu\Music\MediaLibrary\Data\MediaPack;
use Liberu\Music\MediaLibrary\Data\MediaPackRevision;
use Liberu\Music\MediaLibrary\Data\ReadyAsset;
use Liberu\Music\MediaLibrary\Events\MediaPackSaved;
use Liberu\Music\MediaLibrary\Exceptions\InvalidAudio;
use Liberu\Music\MediaLibrary\Exceptions\MetadataConflict;

final readonly class MediaPacks
{
    private const int MAX_DOCUMENT_BYTES = 4194304;

    public function __construct(private ConnectionInterface $database, private MediaAuthorizer $access, private MediaRetention $retention, private Filesystem $objects, private Dispatcher $events, private PackDistributionPolicy $distribution, private ?TransferAuthorizer $transfers = null) {}

    public function save(Authenticatable $actor, SaveMediaPack $command): MediaPack
    {
        $this->access->authorize($actor, $command->workspaceId, $command->packId, 'pack_write');
        $fingerprint = hash('sha256', json_encode([(string) $actor->getAuthIdentifier(), $command], JSON_THROW_ON_ERROR));

        return $this->database->transaction(function () use ($actor, $command, $fingerprint): MediaPack {
            if ($command->expectedVersion === 0) {
                $this->database->table('music_media_packs')->insertOrIgnore(['id' => $command->packId, 'workspace_id' => $command->workspaceId, 'version' => 0]);
            }
            $query = $this->database->table('music_media_packs')->where('id', $command->packId)->where('workspace_id', $command->workspaceId);
            $current = $query->lockForUpdate()->firstOrFail();
            $previous = $this->revisions($command->workspaceId, $command->packId)->where('command_hash', hash('sha256', $command->commandKey))->first();
            if ($previous !== null) {
                if (! hash_equals($fingerprint, $previous->fingerprint)) {
                    throw new MetadataConflict('The pack command key already belongs to another operation.');
                }
                $pack = $this->revision($previous);
                $this->authorizeSnapshot($actor, $pack);
                $this->retain($actor, $pack);

                return $pack;
            }
            if ((int) $current->version !== $command->expectedVersion) {
                throw new MetadataConflict('The pack changed; reload it before saving.');
            }
            $version = $command->expectedVersion + 1;
            $this->retention->retainInTransaction($actor, new RetainAssets($command->workspaceId, $command->packId.':'.$version, $command->assetIds, 'pack'), $this->database);
            $pack = new MediaPack($command->workspaceId, $command->packId, $version, $command->name, $command->rights, $this->assets($actor, $command->workspaceId, $command->assetIds));
            $document = json_encode($pack, JSON_THROW_ON_ERROR);
            if (strlen($document) > self::MAX_DOCUMENT_BYTES) {
                throw ValidationException::withMessages(['pack' => 'The pack document exceeds its bounded manifest size.']);
            }
            if ((clone $query)->where('version', $command->expectedVersion)->update(['version' => $version]) !== 1) {
                throw new MetadataConflict('The pack changed during this save.');
            }
            $this->database->table('music_media_pack_revisions')->insert([
                'pack_id' => $pack->packId, 'workspace_id' => $pack->workspaceId, 'version' => $pack->version,
                'name' => $pack->name, 'asset_count' => count($pack->assets), 'document' => $document, 'checksum' => hash('sha256', $document),
                'command_hash' => hash('sha256', $command->commandKey), 'fingerprint' => $fingerprint,
                'actor_id' => (string) $actor->getAuthIdentifier(), 'created_at' => now('UTC'),
            ]);
            $this->events->dispatch(new MediaPackSaved($pack, (string) $actor->getAuthIdentifier()));

            return $pack;
        }, 3);
    }

    public function get(Authenticatable $actor, string $workspaceId, string $packId, ?int $version = null): MediaPack
    {
        $this->access->authorize($actor, $workspaceId, $packId, 'pack_read');
        Validator::make(['version' => $version], ['version' => ['nullable', 'integer:strict', 'min:1']])->validate();
        $query = $this->revisions($workspaceId, $packId);
        if ($version !== null) {
            $query->where('version', $version);
        }
        $pack = $this->revision($query->orderByDesc('version')->firstOrFail());
        $this->authorizeSnapshot($actor, $pack);

        return $pack;
    }

    /** @return list<MediaPackRevision> */
    public function history(Authenticatable $actor, string $workspaceId, string $packId, int $limit = 25, ?int $before = null): array
    {
        $this->access->authorize($actor, $workspaceId, $packId, 'pack_read');
        Validator::make(['limit' => $limit, 'before' => $before], ['limit' => ['integer:strict', 'between:1,100'], 'before' => ['nullable', 'integer:strict', 'min:1']])->validate();
        $query = $this->revisions($workspaceId, $packId);
        if ($before !== null) {
            $query->where('version', '<', $before);
        }

        return $query->orderByDesc('version')->limit($limit)->get(['version', 'name', 'asset_count', 'created_at'])
            ->map(fn (object $row): MediaPackRevision => new MediaPackRevision((int) $row->version, $row->name, (int) $row->asset_count, $row->created_at))->all();
    }

    public function export(Authenticatable $actor, string $workspaceId, string $packId, ?int $version = null): string
    {
        $this->authorizeTransfer($actor, $workspaceId, $packId, 'export');
        $pack = $this->get($actor, $workspaceId, $packId, $version);
        $this->distribution->authorize($actor, $pack);
        foreach ($pack->assets as $asset) {
            $this->access->authorize($actor, $workspaceId, $asset['reference']['id'], 'distribute');
            Validator::make($asset['provenance'], [
                'source' => ['required', 'string'], 'license' => ['required', 'string'], 'attribution' => ['required', 'string'],
            ])->validate();
        }

        return json_encode(['format' => 'liberu.music.media-pack', 'format_version' => 1, 'document' => $pack,
            'checksum' => hash('sha256', json_encode($pack, JSON_THROW_ON_ERROR))], JSON_THROW_ON_ERROR);
    }

    public function import(Authenticatable $actor, string $workspaceId, string $packId, string $manifest, array $assetMap, string $commandKey): MediaPack
    {
        $this->authorizeTransfer($actor, $workspaceId, $packId, 'import');
        if (strlen($manifest) > self::MAX_DOCUMENT_BYTES * 2) {
            throw ValidationException::withMessages(['manifest' => 'The pack manifest exceeds its size limit.']);
        }
        $input = json_decode($manifest, true, flags: JSON_THROW_ON_ERROR);
        Validator::make(['manifest' => $input, 'map' => $assetMap], [
            'manifest' => ['required', 'array:format,format_version,document,checksum'],
            'manifest.format' => ['required', 'in:liberu.music.media-pack'], 'manifest.format_version' => ['required', 'integer:strict', 'in:1'],
            'manifest.document' => ['required', 'array'], 'manifest.checksum' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'],
            'map' => ['required', 'array', 'max:1000'], 'map.*' => ['required', 'uuid', 'distinct:strict'],
        ])->validate();
        if (! hash_equals($input['checksum'], hash('sha256', json_encode($input['document'], JSON_THROW_ON_ERROR)))) {
            throw ValidationException::withMessages(['manifest' => 'The pack manifest failed its integrity check.']);
        }
        $source = MediaPack::fromArray($input['document']);
        $sourceIds = array_column(array_column($source->assets, 'reference'), 'id');
        $keys = array_keys($assetMap);
        sort($keys, SORT_STRING);
        if ($keys !== $sourceIds) {
            throw ValidationException::withMessages(['map' => 'Map exactly the source asset identities to verified destination assets.']);
        }
        $assets = $this->assets($actor, $workspaceId, array_values($assetMap));
        // Index by the public reference ID; destination storage keys never enter
        // the portable document or an import command.
        $destination = [];
        foreach ($assets as $asset) {
            $destination[$asset['reference']['id']] = $asset;
        }
        foreach ($source->assets as $asset) {
            $asset['reference']['id'] = $assetMap[$asset['reference']['id']];
            $this->assertSameAsset($asset, $destination[$asset['reference']['id']]);
        }

        return $this->save($actor, new SaveMediaPack($workspaceId, $packId, $source->name, array_values($assetMap), $source->rights, 0, $commandKey));
    }

    private function authorizeTransfer(Authenticatable $actor, string $workspaceId, string $packId, string $direction): void
    {
        $this->access->authorize($actor, $workspaceId, $packId, 'pack_'.$direction);
        if ($this->transfers === null || ! $this->transfers->allowed((string) $actor->getAuthIdentifier(), 'music-media-pack', $direction)) {
            throw new AuthorizationException('The foundation transfer policy must authorize this pack operation.');
        }
    }

    private function revisions(string $workspaceId, string $packId): Builder
    {
        return $this->database->table('music_media_pack_revisions')->where('workspace_id', $workspaceId)->where('pack_id', $packId);
    }

    private function revision(object $row): MediaPack
    {
        if (strlen($row->document) > self::MAX_DOCUMENT_BYTES || ! hash_equals($row->checksum, hash('sha256', $row->document))) {
            throw ValidationException::withMessages(['pack' => 'The stored pack failed its integrity check.']);
        }
        $document = json_decode($row->document, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($document)) {
            throw ValidationException::withMessages(['pack' => 'The stored pack document must be an object.']);
        }
        $pack = MediaPack::fromArray($document);
        if ($pack->workspaceId !== $row->workspace_id || $pack->packId !== $row->pack_id || $pack->version !== (int) $row->version) {
            throw ValidationException::withMessages(['pack' => 'The stored pack identity differs from its revision.']);
        }

        return $pack;
    }

    private function authorizeSnapshot(Authenticatable $actor, MediaPack $pack): void
    {
        $ids = array_column(array_column($pack->assets, 'reference'), 'id');
        $current = $this->assets($actor, $pack->workspaceId, $ids);
        foreach ($pack->assets as $index => $asset) {
            $this->assertSameAsset($asset, $current[$index]);
        }
    }

    private function assertSameAsset(array $expected, array $current): void
    {
        if ($expected['reference'] !== $current['reference'] || $expected['provenance'] !== $current['provenance']) {
            throw ValidationException::withMessages(['assets' => 'Pack audio metadata or provenance differs from its verified source.']);
        }
    }

    private function retain(Authenticatable $actor, MediaPack $pack): void
    {
        $ids = array_column(array_column($pack->assets, 'reference'), 'id');
        $this->retention->retainInTransaction($actor, new RetainAssets($pack->workspaceId, $pack->packId.':'.$pack->version, $ids, 'pack'), $this->database);
    }

    private function assets(Authenticatable $actor, string $workspaceId, array $ids): array
    {
        $rows = $this->database->table('music_media_uploads')->where('workspace_id', $workspaceId)->where('status', 'ready')->whereIn('id', $ids)->orderBy('id')
            ->get(['id', 'workspace_id', 'name', 'metadata', 'provenance', 'duplicate_of', 'blob_key']);
        if ($rows->count() !== count($ids) || array_diff($ids, $rows->pluck('id')->all()) !== []) {
            throw new RecordNotFoundException('Every pack asset must be verified in the requested workspace.');
        }
        $assets = [];
        foreach ($rows as $row) {
            $this->access->authorize($actor, $workspaceId, $row->id, 'read');
            if (! $this->objects->exists($row->blob_key)) {
                throw new InvalidAudio('A pack asset is missing; restore its verified bytes first.');
            }
            $provenance = json_decode($row->provenance, true, flags: JSON_THROW_ON_ERROR);
            ksort($provenance, SORT_STRING);
            $asset = new ReadyAsset($row->id, $workspaceId, $row->name, json_decode($row->metadata, true, flags: JSON_THROW_ON_ERROR), $provenance, $row->duplicate_of);
            $assets[] = ['reference' => $asset->reference(), 'name' => $asset->name, 'provenance' => $asset->provenance];
        }

        usort($assets, fn (array $left, array $right): int => strcmp($left['reference']['id'], $right['reference']['id']));

        return $assets;
    }
}
