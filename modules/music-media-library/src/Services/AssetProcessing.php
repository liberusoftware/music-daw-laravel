<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibrary\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Events\Dispatcher as Events;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Liberu\Music\MediaLibrary\Commands\ProcessAsset;
use Liberu\Music\MediaLibrary\Commands\RestoreAsset;
use Liberu\Music\MediaLibrary\Contracts\MediaAuthorizer;
use Liberu\Music\MediaLibrary\Data\AssetTask;
use Liberu\Music\MediaLibrary\Data\ReadyAsset;
use Liberu\Music\MediaLibrary\Events\AssetTaskChanged;
use Liberu\Music\MediaLibrary\Exceptions\UploadConflict;
use Liberu\Music\MediaLibrary\Jobs\ProcessAssetTask;
use LogicException;

final readonly class AssetProcessing
{
    public function __construct(private Connection $database, private MediaUploads $uploads, private MediaCatalog $catalog, private ?UserProvider $users, private Dispatcher $bus, private Events $events, private MediaAuthorizer $access) {}

    public function request(Authenticatable $actor, ProcessAsset $command): AssetTask
    {
        $this->authorize($actor, $command->workspaceId, $command->uploadId, $command->assetId);
        $fingerprint = hash('sha256', json_encode([(string) $actor->getAuthIdentifier(), $command], JSON_THROW_ON_ERROR));

        return $this->database->transaction(function () use ($actor, $command, $fingerprint): AssetTask {
            $upload = $this->uploads->status($actor, $command->workspaceId, $command->uploadId);
            if ($command->assetId !== null) {
                $this->catalog->get($actor, $command->workspaceId, $command->assetId);
            }
            $inserted = $this->database->table('music_media_asset_tasks')->insertOrIgnore([
                'id' => $command->taskId, 'workspace_id' => $command->workspaceId, 'actor_id' => (string) $actor->getAuthIdentifier(),
                'upload_id' => $command->uploadId, 'restored_asset_id' => $command->assetId, 'fingerprint' => $fingerprint,
                'status' => 'queued', 'created_at' => now('UTC'), 'updated_at' => now('UTC'),
            ]);
            $row = $this->owned($actor, $command->workspaceId, $command->taskId)->lockForUpdate()->firstOrFail();
            $this->sameCommand($row, $fingerprint);
            if (in_array($row->status, ['ready', 'cancelled'], true)) {
                return $this->task($row);
            }
            if (! in_array($upload->status, ['uploading', 'ready'], true)) {
                throw new UploadConflict('Only live completed uploads can be processed.');
            }
            if ($upload->receivedBytes !== $upload->totalBytes) {
                throw ValidationException::withMessages(['upload' => 'Receive every declared byte before requesting verification.']);
            }
            $job = new ProcessAssetTask($row->id, (string) Str::uuid());
            $this->change($row, 'queued', ['dispatch_id' => $job->dispatchId], $inserted === 1 || $row->status === 'failed');
            $this->database->afterCommit(fn () => $this->bus->dispatch($job));

            return $this->task($row);
        }, 3);
    }

    public function get(Authenticatable $actor, string $workspaceId, string $taskId): AssetTask
    {
        $row = $this->owned($actor, $workspaceId, $taskId)->firstOrFail();
        $this->authorize($actor, $workspaceId, $row->upload_id, $row->restored_asset_id);

        return $this->task($row);
    }

    public function result(Authenticatable $actor, string $workspaceId, string $taskId): ?ReadyAsset
    {
        $task = $this->get($actor, $workspaceId, $taskId);

        return $task->status === 'ready' ? $this->uploads->get($actor, $workspaceId, $task->assetId()) : null;
    }

    public function cancel(Authenticatable $actor, string $workspaceId, string $taskId): AssetTask
    {
        return $this->database->transaction(function () use ($actor, $workspaceId, $taskId): AssetTask {
            $row = $this->owned($actor, $workspaceId, $taskId)->lockForUpdate()->firstOrFail();
            $this->authorize($actor, $workspaceId, $row->upload_id, $row->restored_asset_id);
            if ($row->status === 'cancelled') {
                return $this->task($row);
            }
            if ($row->status !== 'queued') {
                throw new UploadConflict('This asset task already completed or failed.');
            }
            $this->change($row, 'cancelled');

            return $this->task($row);
        }, 3);
    }

    /** The task lock serializes publication with cancellation: only one can win. */
    public function process(string $taskId, ?string $dispatchId = null): void
    {
        $this->database->transaction(function () use ($taskId, $dispatchId): void {
            $row = $this->database->table('music_media_asset_tasks')->where('id', $taskId)->lockForUpdate()->firstOrFail();
            if ($row->status !== 'queued' || ($dispatchId !== null && $row->dispatch_id !== $dispatchId)) {
                return;
            }
            $actor = $this->users?->retrieveById($row->actor_id);
            if ($actor === null) {
                throw new AuthorizationException('The asset task requester is unavailable.');
            }
            $this->authorize($actor, $row->workspace_id, $row->upload_id, $row->restored_asset_id);
            if ($row->restored_asset_id === null) {
                $this->uploads->complete($actor, $row->workspace_id, $row->upload_id);
            } else {
                $this->uploads->restore($actor, new RestoreAsset($row->workspace_id, $row->restored_asset_id, $row->upload_id, $row->id));
            }
            $this->change($row, 'ready');
        }, 3);
    }

    public function failed(string $taskId, ?string $dispatchId = null): void
    {
        $this->database->transaction(function () use ($taskId, $dispatchId): void {
            $row = $this->database->table('music_media_asset_tasks')->where('id', $taskId)->lockForUpdate()->first();
            if ($row !== null && $row->status === 'queued' && ($dispatchId === null || $row->dispatch_id === $dispatchId)) {
                $this->change($row, 'failed');
            }
        }, 3);
    }

    private function authorize(Authenticatable $actor, string $workspaceId, string $uploadId, ?string $assetId): void
    {
        $this->access->authorize($actor, $workspaceId, $uploadId, 'upload');
        if ($assetId !== null) {
            $this->access->authorize($actor, $workspaceId, $assetId, 'restore');
            $this->access->authorize($actor, $workspaceId, $assetId, 'read');
        }
    }

    private function owned(Authenticatable $actor, string $workspaceId, string $taskId): Builder
    {
        return $this->database->table('music_media_asset_tasks')->where('id', $taskId)->where('workspace_id', $workspaceId)->where('actor_id', (string) $actor->getAuthIdentifier());
    }

    private function sameCommand(object $row, string $fingerprint): void
    {
        if (! hash_equals($row->fingerprint, $fingerprint)) {
            throw new UploadConflict('This asset task identity belongs to another command.');
        }
    }

    private function task(object $row): AssetTask
    {
        return new AssetTask($row->id, $row->workspace_id, $row->upload_id, $row->restored_asset_id, $row->status);
    }

    private function change(object $row, string $status, array $values = [], bool $emit = true): void
    {
        $values += ['status' => $status, 'updated_at' => now('UTC')];
        $updated = $this->database->table('music_media_asset_tasks')->where('id', $row->id)->where('status', $row->status)->update($values);
        if ($updated !== 1) {
            throw new LogicException('The asset task state transition was not acknowledged.');
        }
        foreach ($values as $field => $value) {
            $row->{$field} = $value;
        }
        if ($emit) {
            $this->events->dispatch(new AssetTaskChanged($this->task($row)));
        }
    }
}
