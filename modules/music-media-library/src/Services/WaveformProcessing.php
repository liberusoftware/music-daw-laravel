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
use Liberu\Music\MediaLibrary\Commands\RequestWaveform;
use Liberu\Music\MediaLibrary\Contracts\MediaAuthorizer;
use Liberu\Music\MediaLibrary\Data\Waveform;
use Liberu\Music\MediaLibrary\Data\WaveformTask;
use Liberu\Music\MediaLibrary\Events\WaveformTaskChanged;
use Liberu\Music\MediaLibrary\Exceptions\UploadConflict;
use Liberu\Music\MediaLibrary\Jobs\BuildWaveform;

final readonly class WaveformProcessing
{
    public function __construct(private Connection $database, private MediaUploads $uploads, private CachedWaveforms $waveforms, private ?UserProvider $users, private Dispatcher $bus, private Events $events, private MediaAuthorizer $access) {}

    public function request(Authenticatable $actor, RequestWaveform $command): WaveformTask
    {
        $asset = $this->uploads->get($actor, $command->workspaceId, $command->assetId);

        return $this->database->transaction(function () use ($actor, $command, $asset): WaveformTask {
            $inserted = $this->database->table('music_media_waveform_tasks')->insertOrIgnore([
                'id' => $command->taskId, 'workspace_id' => $command->workspaceId, 'actor_id' => (string) $actor->getAuthIdentifier(),
                'asset_id' => $command->assetId, 'source_sha256' => $asset->metadata['sha256'], 'frames_per_peak' => $command->framesPerPeak,
                'status' => 'queued', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $row = $this->owned($actor, $command->workspaceId, $command->taskId)->lockForUpdate()->firstOrFail();
            if ($row->asset_id !== $command->assetId || (int) $row->frames_per_peak !== $command->framesPerPeak || ! hash_equals($row->source_sha256, $asset->metadata['sha256'])) {
                throw new UploadConflict('This waveform task identifier belongs to another request.');
            }
            if ($row->status === 'cancelled') {
                return $this->task($row);
            }
            if ($row->status !== 'ready' || $this->waveforms->get($actor, $command->workspaceId, $command->assetId, $command->framesPerPeak) === null) {
                $changed = $inserted === 1 || $row->status !== 'queued';
                $this->database->table('music_media_waveform_tasks')->where('id', $row->id)->update(['status' => 'queued', 'updated_at' => now()]);
                $row->status = 'queued';
                if ($changed) {
                    $this->events->dispatch(new WaveformTaskChanged($this->task($row)));
                }
                // A queue outage leaves a durable queued request. Replaying this
                // command re-dispatches it without creating another task identity.
                $this->database->afterCommit(fn () => $this->bus->dispatch(new BuildWaveform($row->id)));
            }

            return $this->task($row);
        }, 3);
    }

    public function get(Authenticatable $actor, string $workspaceId, string $taskId): WaveformTask
    {
        $row = $this->owned($actor, $workspaceId, $taskId)->firstOrFail();
        $this->access->authorize($actor, $workspaceId, $row->asset_id, 'read');

        return $this->task($row);
    }

    public function result(Authenticatable $actor, string $workspaceId, string $taskId): ?Waveform
    {
        $task = $this->get($actor, $workspaceId, $taskId);

        return $task->status === 'ready' ? $this->waveforms->get($actor, $workspaceId, $task->assetId, $task->framesPerPeak) : null;
    }

    public function cancel(Authenticatable $actor, string $workspaceId, string $taskId): WaveformTask
    {
        return $this->database->transaction(function () use ($actor, $workspaceId, $taskId): WaveformTask {
            $row = $this->owned($actor, $workspaceId, $taskId)->lockForUpdate()->firstOrFail();
            $this->access->authorize($actor, $workspaceId, $row->asset_id, 'read');
            if ($row->status === 'cancelled') {
                return $this->task($row);
            }
            if ($row->status !== 'queued') {
                throw new UploadConflict('Only queued or in-flight waveform tasks can be cancelled.');
            }
            $updated = $this->owned($actor, $workspaceId, $taskId)->where('status', 'queued')->update(['status' => 'cancelled', 'updated_at' => now('UTC')]);
            if ($updated !== 1) {
                throw new UploadConflict('The waveform task changed during cancellation.');
            }
            $row->status = 'cancelled';
            $task = $this->task($row);
            $this->events->dispatch(new WaveformTaskChanged($task));

            return $task;
        }, 3);
    }

    /** Queue worker boundary: resolves the canonical user and rechecks live asset permission. */
    public function process(string $taskId): void
    {
        $row = $this->database->table('music_media_waveform_tasks')->where('id', $taskId)->firstOrFail();
        if ($row->status === 'cancelled') {
            return;
        }
        $actor = $this->users?->retrieveById($row->actor_id);
        if ($actor === null) {
            throw new AuthorizationException('The waveform requester is unavailable.');
        }
        $waveform = $this->waveforms->build($actor, $row->workspace_id, $row->asset_id, (int) $row->frames_per_peak);
        if (! hash_equals($row->source_sha256, $waveform->audio->sha256)) {
            throw new UploadConflict('The waveform task source changed.');
        }
        $this->database->transaction(function () use ($row): void {
            $updated = $this->database->table('music_media_waveform_tasks')->where('id', $row->id)->whereIn('status', ['queued', 'failed'])->update(['status' => 'ready', 'updated_at' => now()]);
            if ($updated === 1) {
                $row->status = 'ready';
                $this->events->dispatch(new WaveformTaskChanged($this->task($row)));
            }
        }, 3);
    }

    /** Exhausted queue attempts only; do not expose exception messages or private paths. */
    public function failed(string $taskId): void
    {
        $this->database->transaction(function () use ($taskId): void {
            $row = $this->database->table('music_media_waveform_tasks')->where('id', $taskId)->lockForUpdate()->first();
            if ($row !== null && $row->status === 'queued') {
                $this->database->table('music_media_waveform_tasks')->where('id', $taskId)->update(['status' => 'failed', 'updated_at' => now()]);
                $row->status = 'failed';
                $this->events->dispatch(new WaveformTaskChanged($this->task($row)));
            }
        }, 3);
    }

    private function owned(Authenticatable $actor, string $workspaceId, string $taskId): Builder
    {
        return $this->database->table('music_media_waveform_tasks')->where('id', $taskId)->where('workspace_id', $workspaceId)->where('actor_id', (string) $actor->getAuthIdentifier());
    }

    private function task(object $row): WaveformTask
    {
        return new WaveformTask($row->id, $row->workspace_id, $row->asset_id, (int) $row->frames_per_peak, $row->status);
    }
}
