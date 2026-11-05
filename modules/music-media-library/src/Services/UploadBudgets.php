<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibrary\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Liberu\Music\MediaLibrary\Contracts\MediaAuthorizer;
use Liberu\Music\MediaLibrary\Contracts\UploadBudgetPolicy;
use Liberu\Music\MediaLibrary\Data\UploadBudget;
use Liberu\Music\MediaLibrary\Events\UploadBudgetChanged;
use Liberu\Music\MediaLibrary\Exceptions\UploadBudgetExceeded;
use LogicException;

final readonly class UploadBudgets
{
    public function __construct(private ConnectionInterface $database, private UploadBudgetPolicy $policy, private MediaAuthorizer $access, private Dispatcher $events) {}

    public function get(Authenticatable $actor, string $workspaceId): UploadBudget
    {
        $this->access->authorize($actor, $workspaceId, '*', 'quota_read');
        $used = (int) $this->database->table('music_media_workspace_usage')->where('workspace_id', $workspaceId)->value('reserved_bytes');

        return new UploadBudget($workspaceId, $used, $this->limit($actor, $workspaceId));
    }

    public function reserveInTransaction(Authenticatable $actor, string $workspaceId, string $uploadId, ConnectionInterface $transaction): void
    {
        $this->requireTransaction($transaction);
        $this->access->authorize($actor, $workspaceId, $uploadId, 'upload');
        $upload = $this->database->table('music_media_uploads')->where('workspace_id', $workspaceId)->where('id', $uploadId)
            ->whereIn('status', ['uploading', 'ready'])->lockForUpdate()->firstOrFail(['total_bytes']);
        $previous = $this->database->table('music_media_upload_reservations')->where('upload_id', $uploadId)->first();
        if ($previous !== null) {
            return;
        }
        $limit = $this->limit($actor, $workspaceId);
        $used = $this->lockWorkspace($workspaceId);
        $requested = (int) $upload->total_bytes * 2;
        if ($requested > PHP_INT_MAX - $used) {
            throw new LogicException('The workspace reservation counter exceeds its integer range.');
        }
        $budget = new UploadBudget($workspaceId, $used, $limit);
        if ($limit !== null && $requested > $budget->availableBytes()) {
            throw new UploadBudgetExceeded($budget, $requested);
        }
        $this->change($workspaceId, $used, $used + $requested);
        $this->database->table('music_media_upload_reservations')->insert([
            'upload_id' => $uploadId, 'workspace_id' => $workspaceId, 'reserved_bytes' => $requested,
            'created_at' => now('UTC'), 'released_at' => null,
        ]);
        $this->events->dispatch(new UploadBudgetChanged($workspaceId, $used + $requested, $requested, [$uploadId], (string) $actor->getAuthIdentifier()));
    }

    /** @param list<string> $uploadIds */
    public function releaseCancelledInTransaction(Authenticatable $actor, string $workspaceId, array $uploadIds, ConnectionInterface $transaction): int
    {
        $this->requireTransaction($transaction);
        $rows = $this->database->table('music_media_upload_reservations as reservations')
            ->join('music_media_uploads as uploads', 'uploads.id', '=', 'reservations.upload_id')
            ->where('reservations.workspace_id', $workspaceId)->where('uploads.workspace_id', $workspaceId)
            ->where('uploads.status', 'cancelled')->whereIn('uploads.id', $uploadIds)->where('reservations.reserved_bytes', '>', 0)
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('music_media_upload_chunks as chunks')->whereColumn('chunks.upload_id', 'uploads.id'))
            ->orderBy('uploads.id')->lockForUpdate()->get(['reservations.upload_id', 'reservations.reserved_bytes']);
        $released = (int) $rows->sum('reserved_bytes');
        if ($released === 0) {
            return 0;
        }
        $used = $this->lockWorkspace($workspaceId);
        if ($released > $used) {
            throw new LogicException('The workspace reservation counter would become negative.');
        }
        $this->change($workspaceId, $used, $used - $released);
        $ids = $rows->pluck('upload_id')->all();
        $this->database->table('music_media_upload_reservations')->where('workspace_id', $workspaceId)->whereIn('upload_id', $ids)
            ->update(['reserved_bytes' => 0, 'released_at' => now('UTC')]);
        $this->events->dispatch(new UploadBudgetChanged($workspaceId, $used - $released, -$released, $ids, (string) $actor->getAuthIdentifier()));

        return $released;
    }

    private function requireTransaction(ConnectionInterface $transaction): void
    {
        if ($transaction !== $this->database || $transaction->transactionLevel() < 1) {
            throw new LogicException('Upload reservations must share the active upload transaction.');
        }
    }

    private function limit(Authenticatable $actor, string $workspaceId): ?int
    {
        $limit = $this->policy->maximumReservedBytes($actor, $workspaceId);
        if ($limit !== null && $limit < 0) {
            throw new LogicException('The upload budget policy must return a non-negative byte limit or null.');
        }

        return $limit;
    }

    private function lockWorkspace(string $workspaceId): int
    {
        $this->database->table('music_media_workspace_usage')->insertOrIgnore(['workspace_id' => $workspaceId, 'reserved_bytes' => 0]);
        $row = $this->database->table('music_media_workspace_usage')->where('workspace_id', $workspaceId)->lockForUpdate()->firstOrFail(['reserved_bytes']);

        return (int) $row->reserved_bytes;
    }

    private function change(string $workspaceId, int $before, int $after): void
    {
        $updated = $this->database->table('music_media_workspace_usage')->where('workspace_id', $workspaceId)->where('reserved_bytes', $before)->update(['reserved_bytes' => $after]);
        if ($updated !== 1) {
            throw new LogicException('The workspace reservation counter changed during this operation.');
        }
    }
}
