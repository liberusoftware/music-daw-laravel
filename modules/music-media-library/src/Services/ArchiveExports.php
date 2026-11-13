<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibrary\Services;

use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Events\Dispatcher as Events;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Liberu\Music\MediaLibrary\Commands\RequestArchiveExport;
use Liberu\Music\MediaLibrary\Contracts\MediaAuthorizer;
use Liberu\Music\MediaLibrary\Data\ArchiveExportTask;
use Liberu\Music\MediaLibrary\Data\ArchiveTransfer;
use Liberu\Music\MediaLibrary\Events\ArchiveExportChanged;
use Liberu\Music\MediaLibrary\Exceptions\ArchiveResultExpired;
use Liberu\Music\MediaLibrary\Exceptions\InvalidAudio;
use Liberu\Music\MediaLibrary\Exceptions\UploadConflict;
use Liberu\Music\MediaLibrary\Jobs\ExportPackArchiveTask;
use LogicException;
use RuntimeException;
use Throwable;

final readonly class ArchiveExports
{
    public function __construct(private Connection $database, private Filesystem $objects, private MediaPacks $packs, private PackArchives $archives, private ?UserProvider $users, private Dispatcher $bus, private Events $events, private MediaAuthorizer $access) {}

    public function request(Authenticatable $actor, RequestArchiveExport $command): ArchiveExportTask
    {
        $this->packs->export($actor, $command->workspaceId, $command->packId, $command->version);
        $fingerprint = hash('sha256', json_encode([(string) $actor->getAuthIdentifier(), $command], JSON_THROW_ON_ERROR));

        return $this->database->transaction(function () use ($actor, $command, $fingerprint): ArchiveExportTask {
            $inserted = $this->database->table('music_media_archive_exports')->insertOrIgnore([
                'id' => $command->taskId, 'workspace_id' => $command->workspaceId, 'actor_id' => (string) $actor->getAuthIdentifier(),
                'pack_id' => $command->packId, 'version' => $command->version, 'fingerprint' => $fingerprint,
                'status' => 'queued', 'created_at' => now('UTC'), 'updated_at' => now('UTC'),
            ]);
            $row = $this->owned($actor, $command->workspaceId, $command->taskId)->lockForUpdate()->firstOrFail();
            if (! hash_equals($row->fingerprint, $fingerprint)) {
                throw new UploadConflict('This archive task identity belongs to another command.');
            }
            if (in_array($row->status, ['ready', 'cancelled', 'expired'], true)) {
                return $this->task($row);
            }
            $job = new ExportPackArchiveTask($row->id, (string) Str::uuid());
            $this->change($row, 'queued', ['dispatch_id' => $job->dispatchId], $inserted === 1 || $row->status === 'failed');
            $this->database->afterCommit(fn () => $this->bus->dispatch($job));

            return $this->task($row);
        }, 3);
    }

    public function get(Authenticatable $actor, string $workspaceId, string $taskId): ArchiveExportTask
    {
        $row = $this->owned($actor, $workspaceId, $taskId)->firstOrFail();
        $this->packs->export($actor, $workspaceId, $row->pack_id, (int) $row->version);

        return $this->task($row);
    }

    public function result(Authenticatable $actor, string $workspaceId, string $taskId): ?ArchiveTransfer
    {
        $task = $this->get($actor, $workspaceId, $taskId);
        if ($task->status === 'expired') {
            throw new ArchiveResultExpired('This private archive result has expired.');
        }

        return $task->status === 'ready' ? $task->transfer : null;
    }

    /** @return resource Caller must close the integrity-verified private snapshot. */
    public function open(Authenticatable $actor, string $workspaceId, string $taskId): mixed
    {
        $transfer = $this->result($actor, $workspaceId, $taskId);
        if ($transfer === null) {
            throw new UploadConflict('The archive result is not ready.');
        }

        return $this->snapshot($this->key($workspaceId, $taskId), $transfer);
    }

    public function cancel(Authenticatable $actor, string $workspaceId, string $taskId): ArchiveExportTask
    {
        return $this->database->transaction(function () use ($actor, $workspaceId, $taskId): ArchiveExportTask {
            $this->get($actor, $workspaceId, $taskId);
            $row = $this->owned($actor, $workspaceId, $taskId)->lockForUpdate()->firstOrFail();
            if ($row->status === 'cancelled') {
                return $this->task($row);
            }
            if ($row->status !== 'queued') {
                throw new UploadConflict('This archive task already completed or failed.');
            }
            $this->change($row, 'cancelled');

            return $this->task($row);
        }, 3);
    }

    public function process(string $taskId, ?string $dispatchId = null): void
    {
        $this->database->transaction(function () use ($taskId, $dispatchId): void {
            $row = $this->database->table('music_media_archive_exports')->where('id', $taskId)->lockForUpdate()->firstOrFail();
            if ($row->status !== 'queued' || ($dispatchId !== null && $row->dispatch_id !== $dispatchId)) {
                return;
            }
            $actor = $this->users?->retrieveById($row->actor_id);
            if ($actor === null) {
                throw new AuthorizationException('The archive requester is unavailable.');
            }
            $stream = tmpfile() ?: throw new RuntimeException('Private archive staging is unavailable.');
            try {
                $transfer = $this->archives->exportTo($actor, $row->workspace_id, $row->pack_id, $stream, (int) $row->version);
                rewind($stream);
                $key = $this->key($row->workspace_id, $row->id);
                if (! $this->objects->put($key, $stream, ['visibility' => 'private'])) {
                    throw new RuntimeException('The private archive could not be stored.');
                }
                $verified = $this->snapshot($key, $transfer);
                fclose($verified);
                $this->change($row, 'ready', ['transfer' => json_encode($transfer, JSON_THROW_ON_ERROR), 'expires_at' => CarbonImmutable::now('UTC')->addHour()->format('Y-m-d\TH:i:s\Z')]);
            } finally {
                fclose($stream);
            }
        }, 3);
    }

    public function failed(string $taskId, ?string $dispatchId = null): void
    {
        $this->database->transaction(function () use ($taskId, $dispatchId): void {
            $row = $this->database->table('music_media_archive_exports')->where('id', $taskId)->lockForUpdate()->first();
            if ($row !== null && $row->status === 'queued' && ($dispatchId === null || $row->dispatch_id === $dispatchId)) {
                $this->change($row, 'failed');
            }
        }, 3);
    }

    /** Expired results and failed/cancelled staging older than one day only. */
    public function cleanup(Authenticatable $actor, string $workspaceId, int $limit = 100): int
    {
        $this->access->authorize($actor, $workspaceId, '*', 'archive_cleanup');
        Validator::make(['limit' => $limit], ['limit' => ['integer:strict', 'between:1,100']])->validate();

        return $this->database->transaction(function () use ($workspaceId, $limit): int {
            $rows = $this->database->table('music_media_archive_exports')->where('workspace_id', $workspaceId)
                ->where(fn ($query) => $query->where(fn ($ready) => $ready->where('status', 'ready')->where('expires_at', '<=', now('UTC')->format('Y-m-d\TH:i:s\Z')))
                    ->orWhere(fn ($staging) => $staging->whereIn('status', ['cancelled', 'failed'])->where('updated_at', '<=', now('UTC')->subDay())))
                ->orderBy('id')->limit($limit)->lockForUpdate()->get();
            foreach ($rows as $row) {
                $key = $this->key($workspaceId, $row->id);
                if ($this->objects->exists($key)) {
                    $this->removeArtifact($key);
                }
                $this->change($row, 'expired');
            }

            return $rows->count();
        }, 3);
    }

    private function removeArtifact(string $key): void
    {
        if (! $this->objects->delete($key) || $this->objects->exists($key)) {
            throw new RuntimeException('An expired private archive could not be removed.');
        }
    }

    private function owned(Authenticatable $actor, string $workspaceId, string $taskId): Builder
    {
        return $this->database->table('music_media_archive_exports')->where('id', $taskId)->where('workspace_id', $workspaceId)->where('actor_id', (string) $actor->getAuthIdentifier());
    }

    private function task(object $row): ArchiveExportTask
    {
        $transfer = $row->transfer === null ? null : json_decode($row->transfer, true, flags: JSON_THROW_ON_ERROR);
        $status = $row->status === 'ready' && CarbonImmutable::parse($row->expires_at)->lessThanOrEqualTo(CarbonImmutable::now('UTC')) ? 'expired' : $row->status;

        return new ArchiveExportTask($row->id, $row->workspace_id, $row->pack_id, (int) $row->version, $status,
            $transfer === null ? null : new ArchiveTransfer($transfer['workspace_id'], $transfer['pack_id'], $transfer['version'], $transfer['bytes'], $transfer['sha256'], $transfer['media_objects']), $row->expires_at);
    }

    private function change(object $row, string $status, array $values = [], bool $emit = true): void
    {
        $values += ['status' => $status, 'updated_at' => now('UTC')];
        if ($this->database->table('music_media_archive_exports')->where('id', $row->id)->where('status', $row->status)->update($values) !== 1) {
            throw new LogicException('The archive task transition was not acknowledged.');
        }
        foreach ($values as $field => $value) {
            $row->{$field} = $value;
        }
        if ($emit) {
            $this->events->dispatch(new ArchiveExportChanged($this->task($row)));
        }
    }

    private function key(string $workspaceId, string $taskId): string
    {
        return 'music/archive-exports/'.hash('sha256', $workspaceId).'/'.hash('sha256', $taskId).'.zip';
    }

    /** @return resource */
    private function snapshot(string $key, ArchiveTransfer $transfer): mixed
    {
        $source = $this->objects->readStream($key);
        if (! is_resource($source)) {
            throw new InvalidAudio('The private archive is missing or unreadable.');
        }
        $snapshot = null;
        try {
            $snapshot = tmpfile() ?: throw new RuntimeException('Private archive staging is unavailable.');
            if (stream_copy_to_stream($source, $snapshot, $transfer->bytes + 1) !== $transfer->bytes) {
                throw new InvalidAudio('The private archive size differs from its verified identity.');
            }
            rewind($snapshot);
            $hash = hash_init('sha256');
            hash_update_stream($hash, $snapshot);
            if (! hash_equals($transfer->sha256, hash_final($hash))) {
                throw new InvalidAudio('The private archive checksum differs from its verified identity.');
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
