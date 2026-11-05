<?php

declare(strict_types=1);

namespace Liberu\Music\Projects\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Validator;
use Liberu\Music\Projects\Contracts\ProjectAccess;
use Liberu\Music\Projects\Data\SessionMetadataPage;

/** Metadata only: callers obtain asset-bearing documents through ProjectSessions. */
final readonly class SessionMetadata
{
    public function __construct(private ConnectionInterface $database, private ProjectAccess $access) {}

    public function browse(Authenticatable $actor, string $workspaceId, int $limit = 25, ?int $before = null, ?bool $templates = null): SessionMetadataPage
    {
        $this->access->authorize($actor, $workspaceId, '*', 'list');
        $this->validate($limit, $before);
        $query = $this->database->table('music_project_revisions as revisions')
            ->join('music_projects as projects', function ($join): void {
                $join->on('projects.id', '=', 'revisions.project_id')->on('projects.version', '=', 'revisions.version');
            })->where('revisions.workspace_id', $workspaceId)->where('projects.workspace_id', $workspaceId);
        if ($templates !== null) {
            $query->where('projects.template', $templates);
        }

        return $this->page($actor, $workspaceId, $query, 'id', $limit, $before);
    }

    public function history(Authenticatable $actor, string $workspaceId, string $projectId, int $limit = 25, ?int $before = null): SessionMetadataPage
    {
        $this->access->authorize($actor, $workspaceId, $projectId, 'read');
        $this->validate($limit, $before);
        $this->database->table('music_projects')->where('workspace_id', $workspaceId)->where('id', $projectId)->firstOrFail(['id']);
        $query = $this->database->table('music_project_revisions as revisions')->where('revisions.workspace_id', $workspaceId)->where('revisions.project_id', $projectId);

        return $this->page($actor, $workspaceId, $query, 'version', $limit, $before);
    }

    private function validate(int $limit, ?int $before): void
    {
        Validator::make(['limit' => $limit, 'before' => $before], [
            'limit' => ['integer:strict', 'between:1,100'], 'before' => ['nullable', 'integer:strict', 'min:1'],
        ])->validate();
    }

    private function page(Authenticatable $actor, string $workspaceId, Builder $query, string $cursorColumn, int $limit, ?int $before): SessionMetadataPage
    {
        if ($before !== null) {
            $query->where('revisions.'.$cursorColumn, '<', $before);
        }
        $rows = $query->orderByDesc('revisions.'.$cursorColumn)->limit($limit + 1)
            ->get(['revisions.id', 'revisions.workspace_id', 'revisions.project_id', 'revisions.title', 'revisions.version', 'revisions.checksum', 'revisions.autosave', 'revisions.template', 'revisions.created_at']);
        $hasMore = $rows->count() > $limit;
        $rows = $rows->take($limit);
        $items = [];
        foreach ($rows as $row) {
            $this->access->authorize($actor, $workspaceId, $row->project_id, 'read');
            $items[] = ['workspace_id' => $row->workspace_id, 'project_id' => $row->project_id, 'title' => $row->title,
                'version' => (int) $row->version, 'checksum' => $row->checksum, 'autosave' => (bool) $row->autosave,
                'template' => (bool) $row->template, 'created_at' => $row->created_at];
        }

        return new SessionMetadataPage($items, $hasMore ? (int) $rows->last()->{$cursorColumn} : null);
    }
}
