<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibrary\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\ConnectionInterface;
use Liberu\Foundation\ImportExport\Contracts\TransferAuthorizer;
use Liberu\Music\MediaLibrary\Contracts\MediaAuthorizer;
use Liberu\Music\MediaLibrary\Exceptions\InvalidAudio;
use RuntimeException;
use Throwable;

final readonly class MediaDownloads
{
    public function __construct(private ConnectionInterface $database, private Filesystem $objects, private MediaAuthorizer $access, private ?TransferAuthorizer $transfers = null) {}

    public function authorize(Authenticatable $actor, string $workspaceId, string $assetId): void
    {
        $this->access->authorize($actor, $workspaceId, $assetId, 'read');
        $this->access->authorize($actor, $workspaceId, $assetId, 'download');
        if ($this->transfers === null || ! $this->transfers->allowed((string) $actor->getAuthIdentifier(), 'music-media-asset', 'export')) {
            throw new AuthorizationException('The foundation transfer policy must authorize asset downloads.');
        }
    }

    /**
     * Opens a verified, rewindable private snapshot. The caller must close it.
     * No storage key or filesystem path is part of this public contract.
     *
     * @return resource
     */
    public function open(Authenticatable $actor, string $workspaceId, string $assetId): mixed
    {
        $this->authorize($actor, $workspaceId, $assetId);
        $row = $this->database->table('music_media_uploads')->where('workspace_id', $workspaceId)->where('id', $assetId)->where('status', 'ready')->firstOrFail();
        $source = $this->objects->readStream($row->blob_key);
        if (! is_resource($source)) {
            throw new InvalidAudio('The verified media is missing or unreadable.');
        }
        $snapshot = null;
        try {
            $snapshot = tmpfile() ?: throw new RuntimeException('Temporary download storage is unavailable.');
            if (stream_copy_to_stream($source, $snapshot, (int) $row->total_bytes + 1) !== (int) $row->total_bytes) {
                throw new InvalidAudio('The downloadable media size differs from its verified identity.');
            }
            rewind($snapshot);
            $hash = hash_init('sha256');
            hash_update_stream($hash, $snapshot);
            if (! hash_equals($row->sha256, hash_final($hash))) {
                throw new InvalidAudio('The downloadable media checksum differs from its verified identity.');
            }
            rewind($snapshot);

            return $snapshot;
        } catch (Throwable $exception) {
            if (is_resource($snapshot)) {
                fclose($snapshot);
            }
            throw $exception;
        } finally {
            fclose($source);
        }
    }
}
