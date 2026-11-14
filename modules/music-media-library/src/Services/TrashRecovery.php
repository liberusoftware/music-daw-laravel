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
use Liberu\Music\MediaLibrary\Commands\RecoverTrashedAsset;
use Liberu\Music\MediaLibrary\Commands\RequestTrashRecovery;
use Liberu\Music\MediaLibrary\Contracts\MediaAuthorizer;
use Liberu\Music\MediaLibrary\Data\ReadyAsset;
use Liberu\Music\MediaLibrary\Data\TrashRecoveryTask;
use Liberu\Music\MediaLibrary\Events\TrashRecoveryChanged;
use Liberu\Music\MediaLibrary\Exceptions\UploadConflict;
use Liberu\Music\MediaLibrary\Jobs\RecoverTrashTask;
use LogicException;

final readonly class TrashRecovery
{
    public function __construct(private Connection $database, private AssetLifecycle $lifecycle, private MediaUploads $uploads, private ?UserProvider $users, private Dispatcher $bus, private Events $events, private MediaAuthorizer $access) {}

    public function request(Authenticatable $actor, RequestTrashRecovery $command): TrashRecoveryTask
    {
        $this->authorize($actor, $command->workspaceId, $command->assetId);
        $fingerprint = hash('sha256', json_encode([(string) $actor->getAuthIdentifier(), $command], JSON_THROW_ON_ERROR));

        return $this->database->transaction(function () use ($actor, $command, $fingerprint): TrashRecoveryTask {
            $asset = $this->database->table('music_media_uploads')->where('workspace_id', $command->workspaceId)->where('id', $command->assetId)->firstOrFail();
            $inserted = $this->database->table('music_media_trash_recovery_tasks')->insertOrIgnore([
                'id' => $command->taskId, 'workspace_id' => $command->workspaceId, 'actor_id' => (string) $actor->getAuthIdentifier(),
                'asset_id' => $command->assetId, 'fingerprint' => $fingerprint, 'status' => 'queued', 'created_at' => now('UTC'), 'updated_at' => now('UTC'),
            ]);
            $row = $this->owned($actor, $command->workspaceId, $command->taskId)->lockForUpdate()->firstOrFail();
            if (! hash_equals($row->fingerprint, $fingerprint)) {
                throw new UploadConflict('This recovery task identity belongs to another command.');
            }
            if (in_array($row->status, ['ready', 'cancelled'], true)) {
                return $this->task($row);
            }
            if ($asset->status !== 'trashed') {
                throw new UploadConflict('Only trashed assets can be queued for recovery.');
            }
            $job = new RecoverTrashTask($row->id, (string) Str::uuid());
            $this->change($row, 'queued', ['dispatch_id' => $job->dispatchId], $inserted === 1 || $row->status === 'failed');
            $this->database->afterCommit(fn () => $this->bus->dispatch($job));

            return $this->task($row);
        }, 3);
    }

    public function get(Authenticatable $actor, string $workspaceId, string $taskId): TrashRecoveryTask
    {
        $row = $this->owned($actor, $workspaceId, $taskId)->firstOrFail();
        $this->authorize($actor, $workspaceId, $row->asset_id);

        return $this->task($row);
    }

    public function result(Authenticatable $actor, string $workspaceId, string $taskId): ?ReadyAsset
    {
        $task = $this->get($actor, $workspaceId, $taskId);

        return $task->status === 'ready' ? $this->uploads->get($actor, $workspaceId, $task->assetId) : null;
    }

    public function cancel(Authenticatable $actor, string $workspaceId, string $taskId): TrashRecoveryTask
    {
        return $this->database->transaction(function () use ($actor, $workspaceId, $taskId): TrashRecoveryTask {
            $row = $this->owned($actor, $workspaceId, $taskId)->lockForUpdate()->firstOrFail();
            $this->authorize($actor, $workspaceId, $row->asset_id);
            if ($row->status === 'cancelled') {
                return $this->task($row);
            }
            if ($row->status !== 'queued') {
                throw new UploadConflict('This recovery task already completed or failed.');
            }
            $this->change($row, 'cancelled');

            return $this->task($row);
        }, 3);
    }

    public function process(string $taskId, ?string $dispatchId = null): void
    {
        $this->database->transaction(function () use ($taskId, $dispatchId): void {
            $row = $this->database->table('music_media_trash_recovery_tasks')->where('id', $taskId)->lockForUpdate()->firstOrFail();
            if ($row->status !== 'queued' || ($dispatchId !== null && $row->dispatch_id !== $dispatchId)) {
                return;
            }
            $actor = $this->users?->retrieveById($row->actor_id);
            if ($actor === null) {
                throw new AuthorizationException('The recovery requester is unavailable.');
            }
            $this->authorize($actor, $row->workspace_id, $row->asset_id);
            $this->lifecycle->recover($actor, new RecoverTrashedAsset($row->workspace_id, $row->asset_id, 'trash-recovery:'.$row->id));
            $this->change($row, 'ready');
        }, 3);
    }

    public function failed(string $taskId, ?string $dispatchId = null): void
    {
        $this->database->transaction(function () use ($taskId, $dispatchId): void {
            $row = $this->database->table('music_media_trash_recovery_tasks')->where('id', $taskId)->lockForUpdate()->first();
            if ($row !== null && $row->status === 'queued' && ($dispatchId === null || $row->dispatch_id === $dispatchId)) {
                $this->change($row, 'failed');
            }
        }, 3);
    }

    private function authorize(Authenticatable $actor, string $workspaceId, string $assetId): void
    {
        $this->access->authorize($actor, $workspaceId, $assetId, 'restore');
        $this->access->authorize($actor, $workspaceId, $assetId, 'read');
    }

    private function owned(Authenticatable $actor, string $workspaceId, string $taskId): Builder
    {
        return $this->database->table('music_media_trash_recovery_tasks')->where('id', $taskId)->where('workspace_id', $workspaceId)->where('actor_id', (string) $actor->getAuthIdentifier());
    }

    private function task(object $row): TrashRecoveryTask
    {
        return new TrashRecoveryTask($row->id, $row->workspace_id, $row->asset_id, $row->status);
    }

    private function change(object $row, string $status, array $values = [], bool $emit = true): void
    {
        $values += ['status' => $status, 'updated_at' => now('UTC')];
        $updated = $this->database->table('music_media_trash_recovery_tasks')->where('id', $row->id)->where('status', $row->status)->update($values);
        if ($updated !== 1) {
            throw new LogicException('The recovery state transition was not acknowledged.');
        }
        foreach ($values as $field => $value) {
            $row->{$field} = $value;
        }
        if ($emit) {
            $this->events->dispatch(new TrashRecoveryChanged($this->task($row)));
        }
    }
}
