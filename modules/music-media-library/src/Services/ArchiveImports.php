<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibrary\Services;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Events\Dispatcher as Events;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Liberu\Music\MediaLibrary\Commands\RequestArchiveImport;
use Liberu\Music\MediaLibrary\Contracts\MediaAuthorizer;
use Liberu\Music\MediaLibrary\Data\ArchiveImportTask;
use Liberu\Music\MediaLibrary\Data\MediaPack;
use Liberu\Music\MediaLibrary\Events\ArchiveImportChanged;
use Liberu\Music\MediaLibrary\Exceptions\ArchiveImportStopped;
use Liberu\Music\MediaLibrary\Exceptions\UploadConflict;
use Liberu\Music\MediaLibrary\Jobs\ImportPackArchiveTask;
use Liberu\Music\MediaLibrary\Support\QueuedArchiveImportControl;
use LogicException;
use Throwable;

final readonly class ArchiveImports
{
    public function __construct(private Connection $database, private MediaPacks $packs, private PackArchives $archives, private MediaUploads $uploads, private ?UserProvider $users, private Dispatcher $bus, private Events $events, private MediaAuthorizer $access) {}

    public function request(Authenticatable $actor, RequestArchiveImport $command): ArchiveImportTask
    {
        $this->authorize($actor, $command->workspaceId, $command->inputId, $command->packId);
        $fingerprint = hash('sha256', json_encode([(string) $actor->getAuthIdentifier(), $command], JSON_THROW_ON_ERROR));

        return $this->database->transaction(function () use ($actor, $command, $fingerprint): ArchiveImportTask {
            $input = $this->database->table('music_media_uploads')->where('id', $command->inputId)->where('workspace_id', $command->workspaceId)
                ->where('actor_id', (string) $actor->getAuthIdentifier())->where('purpose', 'archive')->lockForUpdate()->firstOrFail();
            $inserted = $this->database->table('music_media_archive_import_tasks')->insertOrIgnore([
                'id' => $command->taskId, 'workspace_id' => $command->workspaceId, 'actor_id' => (string) $actor->getAuthIdentifier(),
                'input_id' => $command->inputId, 'pack_id' => $command->packId, 'asset_map' => json_encode($command->assetMap, JSON_THROW_ON_ERROR),
                'fingerprint' => $fingerprint, 'status' => 'queued', 'total_assets' => count($command->assetMap), 'created_at' => now('UTC'), 'updated_at' => now('UTC'),
            ]);
            $row = $this->owned($actor, $command->workspaceId, $command->taskId)->lockForUpdate()->firstOrFail();
            if (! hash_equals($row->fingerprint, $fingerprint)) {
                throw new UploadConflict('This import task identity belongs to another command.');
            }
            if (in_array($row->status, ['ready', 'cancelled'], true) || ($row->status === 'running' && ! $this->expired($row))) {
                return $this->task($row);
            }
            if ($row->cancel_requested) {
                $this->change($row, 'cancelled', $this->clearLease());

                return $this->task($row);
            }
            if ($input->status !== 'uploading' || (int) $input->received_bytes !== (int) $input->total_bytes) {
                throw ValidationException::withMessages(['input' => 'Receive every archive byte before requesting import.']);
            }
            if ($this->database->table('music_media_packs')->where('id', $command->packId)->exists()) {
                throw new UploadConflict('Archive import creates a new destination pack.');
            }
            $other = $this->rows()->where('input_id', $row->input_id)->where('id', '!=', $row->id)->whereIn('status', ['queued', 'running'])->lockForUpdate()->first();
            if ($other !== null) {
                throw new UploadConflict('This archive input already has an active import task.');
            }
            $job = new ImportPackArchiveTask($row->id, (string) Str::uuid());
            $this->change($row, 'queued', ['dispatch_id' => $job->dispatchId] + $this->clearLease(), $inserted === 1 || $row->status !== 'queued');
            $this->database->afterCommit(fn () => $this->bus->dispatch($job));

            return $this->task($row);
        }, 3);
    }

    public function get(Authenticatable $actor, string $workspaceId, string $taskId): ArchiveImportTask
    {
        $row = $this->owned($actor, $workspaceId, $taskId)->firstOrFail();
        $this->authorize($actor, $workspaceId, $row->input_id, $row->pack_id);

        return $this->task($row);
    }

    public function result(Authenticatable $actor, string $workspaceId, string $taskId): ?MediaPack
    {
        $task = $this->get($actor, $workspaceId, $taskId);

        return $task->status === 'ready' ? $this->packs->get($actor, $workspaceId, $task->packId, $task->resultVersion) : null;
    }

    public function cancel(Authenticatable $actor, string $workspaceId, string $taskId): ArchiveImportTask
    {
        return $this->database->transaction(function () use ($actor, $workspaceId, $taskId): ArchiveImportTask {
            $row = $this->owned($actor, $workspaceId, $taskId)->lockForUpdate()->firstOrFail();
            $this->authorize($actor, $workspaceId, $row->input_id, $row->pack_id);
            if ($row->status === 'cancelled' || ($row->cancel_requested && ! $this->expired($row))) {
                return $this->task($row);
            }
            if (! in_array($row->status, ['queued', 'running'], true)) {
                throw new UploadConflict('This import already completed or failed.');
            }
            $status = $row->status === 'queued' || $this->expired($row) ? 'cancelled' : 'running';
            $this->change($row, $status, ['cancel_requested' => true] + ($status === 'cancelled' ? $this->clearLease() : []));

            return $this->task($row);
        }, 3);
    }

    public function process(string $taskId, string $dispatchId, int $timeoutSeconds = 60): void
    {
        if ($this->database->transactionLevel() !== 0) {
            throw new LogicException('Archive import workers require independently committed upload progress; do not wrap them in another transaction.');
        }
        if ($timeoutSeconds < 60 || $timeoutSeconds > 3600) {
            throw new LogicException('Invalid archive import worker timeout.');
        }
        $row = $this->claim($taskId, $dispatchId, $timeoutSeconds);
        if ($row === null) {
            return;
        }
        try {
            $actor = $this->users?->retrieveById($row->actor_id);
            if ($actor === null) {
                throw new AuthorizationException('The import requester is unavailable.');
            }
            $this->authorize($actor, $row->workspace_id, $row->input_id, $row->pack_id);
            $input = $this->uploads->openArchive($actor, $row->workspace_id, $row->input_id);
            try {
                $control = new QueuedArchiveImportControl($this, $actor, $row->id, $row->lease_nonce, $timeoutSeconds);
                $control->checkpoint(0, (int) $row->total_assets);
                $this->archives->importFrom($actor, $row->workspace_id, $row->pack_id, $input,
                    json_decode($row->asset_map, true, flags: JSON_THROW_ON_ERROR), 'archive-import:'.$row->id, $control);
            } finally {
                fclose($input);
            }
        } catch (ArchiveImportStopped) {
            // Cancellation or a lost lease never publishes a pack or overwrites a newer worker.
        } catch (Throwable $exception) {
            $this->finishFailure($taskId, $row->lease_nonce);
            throw $exception;
        }
    }

    public function checkpoint(string $taskId, string $nonce, int $completedAssets, int $totalAssets, int $timeoutSeconds): void
    {
        if ($completedAssets < 0 || $completedAssets > $totalAssets || $totalAssets < 1 || $totalAssets > 1000) {
            throw new LogicException('Invalid archive import progress.');
        }
        $active = $this->database->transaction(function () use ($taskId, $nonce, $completedAssets, $totalAssets, $timeoutSeconds): bool {
            $row = $this->rows()->where('id', $taskId)->lockForUpdate()->firstOrFail();
            if (! $this->active($row, $nonce)) {
                return false;
            }
            if ($row->cancel_requested) {
                $this->change($row, 'cancelled', $this->clearLease());

                return false;
            }
            if ((int) $row->total_assets !== $totalAssets) {
                throw new LogicException('The import asset total differs from its immutable mapping.');
            }
            if ((int) $row->completed_assets !== $completedAssets) {
                $this->change($row, 'running', ['completed_assets' => $completedAssets]);
            }
            if (CarbonImmutable::parse($row->lease_expires_at, 'UTC')->lessThanOrEqualTo(CarbonImmutable::now('UTC')->addSeconds(intdiv($timeoutSeconds, 2)))) {
                $this->change($row, 'running', ['lease_expires_at' => $this->leaseExpiry($timeoutSeconds)], false);
            }

            return true;
        }, 3);
        if (! $active) {
            throw new ArchiveImportStopped('This import was cancelled or lost its lease.');
        }
    }

    /** @param Closure(): MediaPack $publish */
    public function publish(Authenticatable $actor, string $taskId, string $nonce, Closure $publish): MediaPack
    {
        $pack = $this->database->transaction(function () use ($actor, $taskId, $nonce, $publish): ?MediaPack {
            $snapshot = $this->rows()->where('id', $taskId)->firstOrFail();
            $input = $this->database->table('music_media_uploads')->where('id', $snapshot->input_id)->lockForUpdate()->firstOrFail();
            $row = $this->rows()->where('id', $taskId)->lockForUpdate()->firstOrFail();
            if ((string) $actor->getAuthIdentifier() !== $row->actor_id) {
                throw new AuthorizationException('Only the canonical import requester can publish this task.');
            }
            if (! $this->active($row, $nonce)) {
                return null;
            }
            if ($row->cancel_requested || $input->status !== 'uploading') {
                $this->change($row, 'cancelled', $this->clearLease());

                return null;
            }
            $this->authorize($actor, $row->workspace_id, $row->input_id, $row->pack_id);
            $pack = $publish();
            if ($pack->workspaceId !== $row->workspace_id || $pack->packId !== $row->pack_id || $pack->version !== 1) {
                throw new LogicException('The published pack differs from its import task.');
            }
            $this->uploads->cancel($actor, $row->workspace_id, $row->input_id);
            $this->change($row, 'ready', ['result_version' => $pack->version, 'completed_assets' => (int) $row->total_assets] + $this->clearLease());

            return $pack;
        }, 3);
        if ($pack === null) {
            throw new ArchiveImportStopped('This import was cancelled or lost its lease.');
        }

        return $pack;
    }

    public function failed(string $taskId, string $dispatchId): void
    {
        $this->database->transaction(function () use ($taskId, $dispatchId): void {
            $row = $this->rows()->where('id', $taskId)->lockForUpdate()->first();
            if ($row !== null && in_array($row->status, ['queued', 'running'], true) && $row->dispatch_id === $dispatchId) {
                $this->change($row, $row->cancel_requested ? 'cancelled' : 'failed', $this->clearLease());
            }
        }, 3);
    }

    private function claim(string $taskId, string $dispatchId, int $timeoutSeconds): ?object
    {
        return $this->database->transaction(function () use ($taskId, $dispatchId, $timeoutSeconds): ?object {
            $row = $this->rows()->where('id', $taskId)->lockForUpdate()->firstOrFail();
            if (! in_array($row->status, ['queued', 'running'], true) || ($row->status === 'queued' && $row->dispatch_id !== $dispatchId)
                || ($row->status === 'running' && ! $this->expired($row))) {
                return null;
            }
            if ($row->cancel_requested) {
                $this->change($row, 'cancelled', $this->clearLease());

                return null;
            }
            $this->change($row, 'running', ['lease_nonce' => (string) Str::uuid(), 'dispatch_id' => $dispatchId,
                'lease_expires_at' => $this->leaseExpiry($timeoutSeconds), 'attempt' => (int) $row->attempt + 1, 'completed_assets' => 0]);

            return $row;
        }, 3);
    }

    private function finishFailure(string $taskId, string $nonce): void
    {
        $this->database->transaction(function () use ($taskId, $nonce): void {
            $row = $this->rows()->where('id', $taskId)->lockForUpdate()->firstOrFail();
            if ($row->status === 'running' && $row->lease_nonce === $nonce) {
                $this->change($row, $row->cancel_requested ? 'cancelled' : 'failed', $this->clearLease());
            }
        }, 3);
    }

    private function authorize(Authenticatable $actor, string $workspaceId, string $inputId, string $packId): void
    {
        $this->packs->authorizeImport($actor, $workspaceId, $packId);
        $this->access->authorize($actor, $workspaceId, $inputId, 'upload');
        $this->access->authorize($actor, $workspaceId, $packId, 'pack_write');
        $this->access->authorize($actor, $workspaceId, '*', 'retain');
    }

    private function rows(): Builder
    {
        return $this->database->table('music_media_archive_import_tasks');
    }

    private function owned(Authenticatable $actor, string $workspaceId, string $taskId): Builder
    {
        return $this->rows()->where('id', $taskId)->where('workspace_id', $workspaceId)->where('actor_id', (string) $actor->getAuthIdentifier());
    }

    private function task(object $row): ArchiveImportTask
    {
        return new ArchiveImportTask($row->id, $row->workspace_id, $row->input_id, $row->pack_id, $row->status, (int) $row->completed_assets,
            (int) $row->total_assets, (int) $row->attempt, (bool) $row->cancel_requested, $row->status === 'running' && $this->expired($row),
            $row->result_version === null ? null : (int) $row->result_version);
    }

    private function active(object $row, string $nonce): bool
    {
        return $row->status === 'running' && $row->lease_nonce === $nonce && ! $this->expired($row);
    }

    private function expired(object $row): bool
    {
        return $row->lease_expires_at === null || CarbonImmutable::parse($row->lease_expires_at, 'UTC')->lessThanOrEqualTo(CarbonImmutable::now('UTC'));
    }

    private function leaseExpiry(int $timeoutSeconds): string
    {
        return CarbonImmutable::now('UTC')->addSeconds($timeoutSeconds + 15)->format('Y-m-d H:i:s');
    }

    private function clearLease(): array
    {
        return ['lease_nonce' => null, 'dispatch_id' => null, 'lease_expires_at' => null];
    }

    private function change(object $row, string $status, array $values = [], bool $emit = true): void
    {
        $values += ['status' => $status, 'updated_at' => now('UTC')];
        if ($this->rows()->where('id', $row->id)->where('status', $row->status)->update($values) !== 1) {
            throw new LogicException('The import task transition was not acknowledged.');
        }
        foreach ($values as $field => $value) {
            $row->{$field} = $value;
        }
        if ($emit) {
            $this->events->dispatch(new ArchiveImportChanged($this->task($row)));
        }
    }
}
