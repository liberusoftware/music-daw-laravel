<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibrary\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Liberu\Foundation\Files\Contracts\MalwareScanner;
use Liberu\Music\MediaLibrary\Commands\RestoreAsset;
use Liberu\Music\MediaLibrary\Commands\StartUpload;
use Liberu\Music\MediaLibrary\Contracts\MediaAuthorizer;
use Liberu\Music\MediaLibrary\Data\ReadyAsset;
use Liberu\Music\MediaLibrary\Data\UploadProgress;
use Liberu\Music\MediaLibrary\Events\AssetReady;
use Liberu\Music\MediaLibrary\Events\AssetRestored;
use Liberu\Music\MediaLibrary\Exceptions\InvalidAudio;
use Liberu\Music\MediaLibrary\Exceptions\UploadConflict;
use RuntimeException;

final readonly class MediaUploads
{
    public function __construct(
        private ConnectionInterface $database,
        private Filesystem $objects,
        private MediaAuthorizer $access,
        private WaveInspector $inspector,
        private Dispatcher $events,
        private ?MalwareScanner $scanner = null,
    ) {}

    public function start(Authenticatable $actor, StartUpload $command): UploadProgress
    {
        $this->access->authorize($actor, $command->workspaceId, $command->uploadId, 'upload');
        $fingerprint = hash('sha256', json_encode([(string) $actor->getAuthIdentifier(), $command], JSON_THROW_ON_ERROR));

        return $this->database->transaction(function () use ($actor, $command, $fingerprint): UploadProgress {
            $this->database->table('music_media_uploads')->insertOrIgnore([
                'id' => $command->uploadId, 'workspace_id' => $command->workspaceId,
                'actor_id' => (string) $actor->getAuthIdentifier(), 'name' => $command->name,
                'search_name' => Str::lower($command->name),
                'total_bytes' => $command->bytes, 'received_bytes' => 0, 'sha256' => $command->sha256,
                'fingerprint' => $fingerprint, 'status' => 'uploading',
                'provenance' => json_encode($command->provenance, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $upload = $this->upload($command->workspaceId, $command->uploadId)->lockForUpdate()->firstOrFail();
            if (! hash_equals($upload->fingerprint, $fingerprint)) {
                throw new UploadConflict('This upload identifier was already used for different content.');
            }

            return $this->progress($upload);
        }, 3);
    }

    public function status(Authenticatable $actor, string $workspaceId, string $uploadId): UploadProgress
    {
        $this->access->authorize($actor, $workspaceId, $uploadId, 'upload');

        return $this->progress($this->upload($workspaceId, $uploadId)->firstOrFail());
    }

    public function append(Authenticatable $actor, string $workspaceId, string $uploadId, int $offsetBytes, string $bytes): UploadProgress
    {
        $this->access->authorize($actor, $workspaceId, $uploadId, 'upload');
        Validator::make(['offset' => $offsetBytes, 'length' => strlen($bytes)], [
            'offset' => ['integer:strict', 'min:0'], 'length' => ['integer:strict', 'between:1,4194304'],
        ])->validate();
        $checksum = hash('sha256', $bytes);

        return $this->database->transaction(function () use ($workspaceId, $uploadId, $offsetBytes, $bytes, $checksum): UploadProgress {
            $upload = $this->upload($workspaceId, $uploadId)->lockForUpdate()->firstOrFail();
            if ($upload->status === 'cancelled') {
                throw new UploadConflict('This upload was cancelled.');
            }
            $chunks = $this->database->table('music_media_upload_chunks')->where('upload_id', $uploadId);
            $existing = (clone $chunks)->where('offset_bytes', $offsetBytes)->first();
            if ($existing !== null) {
                if (! hash_equals($existing->sha256, $checksum) || (int) $existing->bytes !== strlen($bytes)) {
                    throw new UploadConflict('A different chunk already occupies this offset.');
                }

                if ($upload->status === 'uploading' && ! $this->objects->put($existing->object_key, $bytes, ['visibility' => 'private'])) {
                    throw new RuntimeException('The replayed upload chunk could not be restored.');
                }

                return $this->progress($upload);
            }
            if ($upload->status !== 'uploading' || (int) $upload->received_bytes !== $offsetBytes
                || $offsetBytes + strlen($bytes) > (int) $upload->total_bytes || $chunks->count() >= 4096) {
                throw new UploadConflict('The chunk does not match the next upload position or upload limits.');
            }
            $key = 'music/quarantine/'.$uploadId.'/'.$offsetBytes.'-'.$checksum;
            if (! $this->objects->put($key, $bytes, ['visibility' => 'private'])) {
                throw new RuntimeException('The upload chunk could not be stored.');
            }
            $chunks->insert(['upload_id' => $uploadId, 'offset_bytes' => $offsetBytes,
                'bytes' => strlen($bytes), 'sha256' => $checksum, 'object_key' => $key]);
            $this->upload($workspaceId, $uploadId)->update(['received_bytes' => $offsetBytes + strlen($bytes), 'updated_at' => now()]);
            $upload->received_bytes = $offsetBytes + strlen($bytes);

            return $this->progress($upload);
        }, 3);
    }

    public function complete(Authenticatable $actor, string $workspaceId, string $uploadId): ReadyAsset
    {
        return $this->completeUpload($actor, $workspaceId, $uploadId);
    }

    public function restore(Authenticatable $actor, RestoreAsset $command): ReadyAsset
    {
        $this->access->authorize($actor, $command->workspaceId, $command->assetId, 'restore');
        $this->access->authorize($actor, $command->workspaceId, $command->assetId, 'read');

        return $this->completeUpload($actor, $command->workspaceId, $command->replacementUploadId, $command);
    }

    private function completeUpload(Authenticatable $actor, string $workspaceId, string $uploadId, ?RestoreAsset $restore = null): ReadyAsset
    {
        $this->access->authorize($actor, $workspaceId, $uploadId, 'upload');

        return $this->database->transaction(function () use ($actor, $workspaceId, $uploadId, $restore): ReadyAsset {
            $upload = $this->upload($workspaceId, $uploadId)->lockForUpdate()->firstOrFail();
            $target = null;
            $fingerprint = null;
            if ($restore !== null) {
                $target = $this->upload($workspaceId, $restore->assetId)->where('status', 'ready')->lockForUpdate()->firstOrFail();
                $fingerprint = hash('sha256', json_encode([(string) $actor->getAuthIdentifier(), $restore], JSON_THROW_ON_ERROR));
                $previous = $this->database->table('music_media_restore_commands')->where('asset_id', $target->id)->where('command_hash', hash('sha256', $restore->commandKey))->first();
                if ($previous !== null) {
                    if (! hash_equals($previous->fingerprint, $fingerprint)) {
                        throw new UploadConflict('The restore command key already belongs to another operation.');
                    }
                    $this->assertStored($target->blob_key, $target->sha256, (int) $target->total_bytes);

                    return $this->asset($target);
                }
                if (! hash_equals($target->sha256, $upload->sha256) || (int) $target->total_bytes !== (int) $upload->total_bytes) {
                    throw new UploadConflict('Replacement audio must match the original asset checksum and size.');
                }
            }
            $wasReady = $upload->status === 'ready';
            if ($wasReady && $restore === null) {
                $this->assertPresent($upload);

                return $this->asset($upload);
            }
            if (! in_array($upload->status, ['uploading', 'ready'], true) || (int) $upload->received_bytes !== (int) $upload->total_bytes) {
                throw new UploadConflict('All upload bytes must be received before verification.');
            }
            $stream = tmpfile() ?: throw new RuntimeException('Temporary audio storage is unavailable.');
            try {
                $chunks = $this->database->table('music_media_upload_chunks')->where('upload_id', $uploadId)->orderBy('offset_bytes')->cursor();
                $position = 0;
                foreach ($chunks as $chunk) {
                    if ((int) $chunk->offset_bytes !== $position) {
                        throw new InvalidAudio('The upload contains a gap.');
                    }
                    $source = $this->readObject($chunk->object_key);
                    try {
                        if (stream_copy_to_stream($source, $stream, (int) $chunk->bytes + 1) !== (int) $chunk->bytes) {
                            throw new InvalidAudio('An upload chunk is incomplete.');
                        }
                    } finally {
                        fclose($source);
                    }
                    $position += (int) $chunk->bytes;
                }
                if ($position !== (int) $upload->total_bytes) {
                    throw new InvalidAudio('The assembled upload is incomplete.');
                }
                if ($this->scanner === null || ! $this->scanner->clean(stream_get_meta_data($stream)['uri'])) {
                    throw new InvalidAudio('Audio remains quarantined until the configured malware scanner accepts it.');
                }
                $audio = $this->inspector->inspect($stream);
                if ($audio->bytes !== (int) $upload->total_bytes || ! hash_equals($upload->sha256, $audio->sha256)) {
                    throw new InvalidAudio('The verified upload checksum or size differs from its declaration.');
                }
                $key = 'music/assets/'.hash('sha256', $workspaceId).'/'.$audio->sha256;
                if ($target !== null && $target->blob_key !== $key) {
                    throw new UploadConflict('The stored asset location differs from its content address.');
                }
                if ($restore !== null || ! $this->objects->exists($key)) {
                    $this->publishBlob($key, $stream, $audio->sha256, $audio->bytes);
                }
                $this->assertStored($key, $audio->sha256, $audio->bytes);
                if (! $wasReady) {
                    $duplicate = $this->database->table('music_media_uploads')->where('workspace_id', $workspaceId)
                        ->where('sha256', $audio->sha256)->where('status', 'ready')->where('id', '!=', $uploadId)->orderBy('id')->value('id');
                    $metadata = ['schema_version' => 1] + $audio->jsonSerialize();
                    $this->upload($workspaceId, $uploadId)->update(['status' => 'ready', 'blob_key' => $key,
                        'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR), 'duplicate_of' => $duplicate, 'updated_at' => now()]);
                    $asset = new ReadyAsset($uploadId, $workspaceId, $upload->name, $metadata, json_decode($upload->provenance, true, flags: JSON_THROW_ON_ERROR), $duplicate);
                    $this->events->dispatch(new AssetReady($asset, (string) $actor->getAuthIdentifier()));
                } else {
                    $asset = $this->asset($upload);
                }
                if ($restore !== null) {
                    $asset = $this->asset($target);
                    $this->database->table('music_media_restore_commands')->insert(['asset_id' => $target->id, 'source_upload_id' => $uploadId,
                        'command_hash' => hash('sha256', $restore->commandKey), 'fingerprint' => $fingerprint,
                        'actor_id' => (string) $actor->getAuthIdentifier(), 'created_at' => now()]);
                    $this->events->dispatch(new AssetRestored($asset, $uploadId, (string) $actor->getAuthIdentifier()));
                }

                return $asset;
            } finally {
                fclose($stream);
            }
        }, 3);
    }

    public function get(Authenticatable $actor, string $workspaceId, string $assetId): ReadyAsset
    {
        $this->access->authorize($actor, $workspaceId, $assetId, 'read');
        $upload = $this->upload($workspaceId, $assetId)->where('status', 'ready')->firstOrFail();
        $this->assertPresent($upload);

        return $this->asset($upload);
    }

    public function cancel(Authenticatable $actor, string $workspaceId, string $uploadId): UploadProgress
    {
        $this->access->authorize($actor, $workspaceId, $uploadId, 'upload');

        return $this->database->transaction(function () use ($workspaceId, $uploadId): UploadProgress {
            $upload = $this->upload($workspaceId, $uploadId)->lockForUpdate()->firstOrFail();
            if ($upload->status === 'ready') {
                throw new UploadConflict('Published assets require retention checks and cannot be cancelled.');
            }
            $this->upload($workspaceId, $uploadId)->update(['status' => 'cancelled', 'updated_at' => now()]);
            $upload->status = 'cancelled';

            return $this->progress($upload);
        }, 3);
    }

    private function upload(string $workspaceId, string $uploadId): Builder
    {
        return $this->database->table('music_media_uploads')->where('workspace_id', $workspaceId)->where('id', $uploadId);
    }

    private function progress(object $row): UploadProgress
    {
        return new UploadProgress($row->id, $row->workspace_id, $row->status, (int) $row->received_bytes, (int) $row->total_bytes);
    }

    private function asset(object $row): ReadyAsset
    {
        return new ReadyAsset($row->id, $row->workspace_id, $row->name, json_decode($row->metadata, true, flags: JSON_THROW_ON_ERROR), json_decode($row->provenance, true, flags: JSON_THROW_ON_ERROR), $row->duplicate_of);
    }

    private function assertPresent(object $row): void
    {
        if (! $this->objects->exists($row->blob_key)) {
            throw new InvalidAudio('The verified media file is missing; restore it before opening this asset.');
        }
    }

    /** @param resource $stream */
    private function publishBlob(string $key, mixed $stream, string $checksum, int $bytes): void
    {
        $staged = $key.'.pending-'.Str::uuid();
        rewind($stream);
        try {
            if (! $this->objects->put($staged, $stream, ['visibility' => 'private'])) {
                throw new RuntimeException('The verified asset could not be stored.');
            }
            $this->assertStored($staged, $checksum, $bytes);
            // Supported stores must replace a complete object atomically. The local
            // driver renames the verified staging file on the same filesystem.
            if (! $this->objects->move($staged, $key)) {
                throw new RuntimeException('The verified asset could not be published.');
            }
        } finally {
            $this->objects->delete($staged);
        }
    }

    private function assertStored(string $key, string $checksum, int $bytes): void
    {
        $blob = $this->readObject($key);
        try {
            $hash = hash_init('sha256');
            $length = hash_update_stream($hash, $blob, $bytes + 1);
            if ($length !== $bytes || ! hash_equals($checksum, hash_final($hash))) {
                throw new InvalidAudio('The stored asset failed its integrity check.');
            }
        } finally {
            fclose($blob);
        }
    }

    /** @return resource */
    private function readObject(string $key): mixed
    {
        $stream = $this->objects->readStream($key);
        if (! is_resource($stream)) {
            throw new InvalidAudio('A required media object is missing or unreadable.');
        }

        return $stream;
    }
}
