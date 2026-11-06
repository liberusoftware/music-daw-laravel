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
use Illuminate\Validation\ValidationException;
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
use Throwable;

final readonly class MediaUploads
{
    public function __construct(
        private ConnectionInterface $database,
        private Filesystem $objects,
        private MediaAuthorizer $access,
        private WaveInspector $inspector,
        private Dispatcher $events,
        private UploadBudgets $budgets,
        private ContentLocks $contentLocks,
        private ?MalwareScanner $scanner = null,
    ) {}

    public function start(Authenticatable $actor, StartUpload $command): UploadProgress
    {
        $this->access->authorize($actor, $command->workspaceId, $command->uploadId, 'upload');
        $fingerprint = hash('sha256', json_encode([(string) $actor->getAuthIdentifier(), $command], JSON_THROW_ON_ERROR));

        return $this->database->transaction(function () use ($actor, $command, $fingerprint): UploadProgress {
            $inserted = $this->database->table('music_media_uploads')->insertOrIgnore([
                'id' => $command->uploadId, 'workspace_id' => $command->workspaceId,
                'actor_id' => (string) $actor->getAuthIdentifier(), 'name' => $command->name, 'purpose' => $command->purpose,
                'search_name' => Str::lower($command->name),
                'total_bytes' => $command->bytes, 'received_bytes' => 0, 'sha256' => $command->sha256,
                'fingerprint' => $fingerprint, 'status' => 'uploading',
                'provenance' => json_encode($command->provenance, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $upload = $this->upload($command->workspaceId, $command->uploadId)->lockForUpdate()->firstOrFail();
            if (! hash_equals($upload->fingerprint, $fingerprint)) {
                throw new UploadConflict('This upload identifier was already used for different content.');
            }

            if ($inserted === 1) {
                $this->budgets->reserveInTransaction($actor, $command->workspaceId, $command->uploadId, $this->database);
            }

            return $this->progress($upload);
        }, 3);
    }

    /**
     * @param  array<array-key, mixed>  $commands
     * @return list<UploadProgress>
     */
    public function startBatch(Authenticatable $actor, array $commands): array
    {
        Validator::make(['commands' => $commands], ['commands' => ['required', 'array', 'list', 'max:1000']])->validate();
        $ids = [];
        $typed = [];
        foreach ($commands as $command) {
            if (! $command instanceof StartUpload) {
                throw ValidationException::withMessages(['commands' => 'Every upload declaration must be a typed StartUpload command.']);
            }
            $ids[] = $command->uploadId;
            $typed[] = $command;
        }
        if (count(array_unique($ids)) !== count($ids)) {
            throw ValidationException::withMessages(['commands' => 'A batch must declare each destination upload identifier once.']);
        }
        $ordered = $typed;
        usort($ordered, fn (StartUpload $left, StartUpload $right): int => strcmp($left->workspaceId."\0".$left->uploadId, $right->workspaceId."\0".$right->uploadId));

        return $this->database->transaction(function () use ($actor, $typed, $ordered): array {
            $started = [];
            foreach ($ordered as $command) {
                $progress = $this->start($actor, $command);
                if (! in_array($progress->status, ['uploading', 'ready'], true)) {
                    throw new UploadConflict('Terminal upload identities cannot be accepted in a new batch.');
                }
                $started[$command->uploadId] = $progress;
            }

            return array_map(fn (StartUpload $command): UploadProgress => $started[$command->uploadId], $typed);
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
                || $offsetBytes + strlen($bytes) > (int) $upload->total_bytes || $chunks->count() >= ($upload->purpose === 'archive' ? 8192 : 4096)) {
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
            if ($upload->purpose !== 'audio') {
                throw new UploadConflict('Archive inputs cannot be published as audio.');
            }
            $this->contentLocks->acquire($workspaceId, $upload->sha256, $this->database);
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
            $stream = $this->assemble($upload);
            try {
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

        return $this->database->transaction(function () use ($actor, $workspaceId, $uploadId): UploadProgress {
            $upload = $this->upload($workspaceId, $uploadId)->lockForUpdate()->firstOrFail();
            if (! in_array($upload->status, ['uploading', 'cancelled'], true)) {
                throw new UploadConflict('Published assets require lifecycle operations and cannot be cancelled.');
            }
            if ($upload->status === 'cancelled') {
                $this->budgets->releaseCancelledInTransaction($actor, $workspaceId, [$uploadId], $this->database);

                return $this->progress($upload);
            }
            $this->upload($workspaceId, $uploadId)->update(['status' => 'cancelled', 'cancelled_at' => now('UTC'), 'updated_at' => now()]);
            $upload->status = 'cancelled';
            $this->budgets->releaseCancelledInTransaction($actor, $workspaceId, [$uploadId], $this->database);

            return $this->progress($upload);
        }, 3);
    }

    /** @return resource A scanner-approved, checksum-verified private input; caller must close it. */
    public function openArchive(Authenticatable $actor, string $workspaceId, string $uploadId): mixed
    {
        $this->access->authorize($actor, $workspaceId, $uploadId, 'upload');
        $upload = $this->upload($workspaceId, $uploadId)->where('actor_id', (string) $actor->getAuthIdentifier())->where('purpose', 'archive')->firstOrFail();
        if ($upload->status !== 'uploading' || (int) $upload->received_bytes !== (int) $upload->total_bytes) {
            throw new UploadConflict('Only complete active archive inputs can be opened.');
        }
        $stream = $this->assemble($upload);
        try {
            $hash = hash_init('sha256');
            $length = hash_update_stream($hash, $stream, (int) $upload->total_bytes + 1);
            if ($length !== (int) $upload->total_bytes || ! hash_equals($upload->sha256, hash_final($hash))) {
                throw new InvalidAudio('The archive input checksum differs from its declaration.');
            }
            rewind($stream);

            return $stream;
        } catch (Throwable $exception) {
            fclose($stream);
            throw $exception;
        }
    }

    /** @return resource */
    private function assemble(object $upload): mixed
    {
        $stream = tmpfile() ?: throw new RuntimeException('Temporary media storage is unavailable.');
        try {
            $chunks = $this->database->table('music_media_upload_chunks')->where('upload_id', $upload->id)->orderBy('offset_bytes')->cursor();
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
            fflush($stream) ?: throw new RuntimeException('Private media staging could not be flushed.');
            if ($this->scanner === null || ! $this->scanner->clean(stream_get_meta_data($stream)['uri'])) {
                throw new InvalidAudio('Media remains quarantined until the configured malware scanner accepts it.');
            }
            rewind($stream);

            return $stream;
        } catch (Throwable $exception) {
            fclose($stream);
            throw $exception;
        }
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
