<?php

declare(strict_types=1);

namespace Liberu\Music\Projects\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Validator;
use Liberu\Music\Projects\Contracts\ProjectAccess;
use Liberu\Music\Projects\Data\SessionChangesPage;

/** Durable revision notifications, not audio-clock or transport events. */
final readonly class SessionChanges
{
    public function __construct(private ConnectionInterface $database, private ProjectAccess $access) {}

    public function since(Authenticatable $actor, string $workspaceId, string $projectId, int $after = 0, int $limit = 25): SessionChangesPage
    {
        $this->access->authorize($actor, $workspaceId, $projectId, 'read');
        Validator::make(['after' => $after, 'limit' => $limit], [
            'after' => ['integer:strict', 'min:0'], 'limit' => ['integer:strict', 'between:1,100'],
        ])->validate();
        // Fix the upper bound before reading the page. Concurrent commits are
        // delivered on a later poll rather than changing this page's horizon.
        $project = $this->database->table('music_projects')->where('workspace_id', $workspaceId)->where('id', $projectId)->firstOrFail(['version']);
        $latest = (int) $project->version;
        if ($after > $latest) {
            return new SessionChangesPage([], $after, $latest, false, true);
        }
        $rows = $this->database->table('music_project_revisions')->where('workspace_id', $workspaceId)->where('project_id', $projectId)
            ->where('version', '>', $after)->where('version', '<=', $latest)->orderBy('version')->limit($limit + 1)
            ->get(['version', 'autosave', 'template', 'checksum', 'created_at']);
        $hasMore = $rows->count() > $limit;
        $cursor = $after;
        $changes = [];
        foreach ($rows->take($limit) as $row) {
            if ((int) $row->version !== $cursor + 1) {
                return new SessionChangesPage([], $after, $latest, false, true);
            }
            $cursor = (int) $row->version;
            $changes[] = ['version' => $cursor, 'autosave' => (bool) $row->autosave, 'template' => (bool) $row->template,
                'checksum' => $row->checksum, 'created_at' => $row->created_at];
        }
        if (! $hasMore && $cursor !== $latest) {
            return new SessionChangesPage([], $after, $latest, false, true);
        }

        return new SessionChangesPage($changes, $cursor, $latest, $hasMore, false);
    }
}
