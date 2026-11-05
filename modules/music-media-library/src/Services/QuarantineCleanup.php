<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibrary\Services;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Liberu\Music\MediaLibrary\Commands\CollectCancelledUploads;
use Liberu\Music\MediaLibrary\Contracts\MediaAuthorizer;
use Liberu\Music\MediaLibrary\Data\QuarantineCollection;
use Liberu\Music\MediaLibrary\Events\QuarantineCollected;
use LogicException;
use RuntimeException;

final readonly class QuarantineCleanup
{
    public function __construct(private ConnectionInterface $database, private Filesystem $objects, private MediaAuthorizer $access, private Dispatcher $events, private UploadBudgets $budgets) {}

    public function collect(Authenticatable $actor, CollectCancelledUploads $command): QuarantineCollection
    {
        $this->access->authorize($actor, $command->workspaceId, '*', 'collect');
        $cutoff = CarbonImmutable::now('UTC')->subHours($command->graceHours);

        return $this->database->transaction(function () use ($actor, $command, $cutoff): QuarantineCollection {
            $query = $this->database->table('music_media_upload_chunks as chunks')
                ->join('music_media_uploads as uploads', 'uploads.id', '=', 'chunks.upload_id')
                ->where('uploads.workspace_id', $command->workspaceId)->where('uploads.status', 'cancelled')
                ->where('uploads.cancelled_at', '<=', $cutoff->format('Y-m-d H:i:s'))
                ->where('chunks.id', '>', $command->afterChunkId)->orderBy('chunks.id')->limit($command->limit + 1);
            if (! $command->dryRun) {
                $query->lockForUpdate();
            }
            $rows = $query->get(['chunks.id', 'chunks.upload_id', 'chunks.offset_bytes', 'chunks.sha256', 'chunks.object_key', 'chunks.bytes']);
            $hasMore = $rows->count() > $command->limit;
            $rows = $rows->take($command->limit);
            $ids = [];
            $bytes = 0;
            foreach ($rows as $row) {
                $this->access->authorize($actor, $command->workspaceId, $row->upload_id, 'collect');
                $expected = 'music/quarantine/'.$row->upload_id.'/'.$row->offset_bytes.'-'.$row->sha256;
                if (! Str::isUuid($row->upload_id) || ! ctype_digit((string) $row->offset_bytes) || ! preg_match('/^[a-f0-9]{64}$/D', $row->sha256) || ! hash_equals($expected, $row->object_key)) {
                    throw new LogicException('The quarantine ledger contains an invalid object address.');
                }
                $ids[] = (int) $row->id;
                $bytes += (int) $row->bytes;
            }
            $report = new QuarantineCollection($command->workspaceId, $cutoff->format('Y-m-d\TH:i:s\Z'), $command->dryRun, $ids, $bytes, $hasMore ? $ids[count($ids) - 1] : null);
            if (! $command->dryRun && $ids !== []) {
                // A crash can remove files before the ledger commits. Missing chunks
                // are acknowledged on retry; cancelled uploads can never consume them.
                foreach ($rows as $row) {
                    if ($this->objects->exists($row->object_key)) {
                        $this->removeChunk($row->object_key);
                    }
                }
                $this->database->table('music_media_upload_chunks')->whereIn('id', $ids)->delete();
                $this->budgets->releaseCancelledInTransaction($actor, $command->workspaceId, array_values(array_unique($rows->pluck('upload_id')->all())), $this->database);
                $this->events->dispatch(new QuarantineCollected($report, (string) $actor->getAuthIdentifier()));
            }

            return $report;
        }, 3);
    }

    private function removeChunk(string $key): void
    {
        if (! $this->objects->delete($key) || $this->objects->exists($key)) {
            throw new RuntimeException('A cancelled upload chunk could not be removed.');
        }
    }
}
