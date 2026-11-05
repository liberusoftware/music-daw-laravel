<?php

declare(strict_types=1);

namespace Liberu\Music\ProjectsApi\Http\Controllers;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\JsonResponse;
use Liberu\Music\Projects\Contracts\ProjectAccess;
use Liberu\Music\Projects\Services\ProjectSessions;
use Liberu\Music\Projects\Services\SessionChanges;
use Liberu\Music\Projects\Services\SessionMetadata;
use Liberu\Music\ProjectsApi\Http\Requests\ReadSessionRequest;
use Liberu\Music\ProjectsApi\Http\Requests\RevisionChangesRequest;
use Liberu\Music\ProjectsApi\Support\RevisionCursor;

final class SessionQueriesController
{
    public function capabilities(ReadSessionRequest $request, ProjectAccess $access, string $workspace): JsonResponse
    {
        $access->authorize($request->user(), $workspace, '*', 'list');

        return $this->response($request, ['schema_version' => 1, 'document_schema_versions' => [1],
            'operations' => ['browse', 'read', 'history', 'export', 'import_preview', 'checkpoint', 'recover', 'import', 'changes'],
            'unsupported' => ['revision_stream', 'audio_execution', 'remote_transport', 'asset_transfer', 'async_jobs']]);
    }

    public function index(ReadSessionRequest $request, SessionMetadata $sessions, string $workspace): JsonResponse
    {
        $templates = $request->validated('templates');
        $page = $sessions->browse($request->user(), $workspace, (int) $request->validated('page.size', 25), $request->cursor(), $templates === null ? null : (bool) $templates);

        return $this->response($request, $this->metadata($page->items), $page->nextCursor);
    }

    public function show(ReadSessionRequest $request, ProjectSessions $sessions, RevisionCursor $cursors, string $workspace, string $project): JsonResponse
    {
        $revision = $sessions->get($request->user(), $workspace, $project, $this->version($request));

        return $this->response($request, $revision, changesCursor: $cursors->encode($workspace, $project, $revision->version))->header('ETag', '"'.$revision->version.'"');
    }

    public function changes(RevisionChangesRequest $request, SessionChanges $changes, RevisionCursor $cursors, string $workspace, string $project): JsonResponse
    {
        $page = $changes->since($request->user(), $workspace, $project,
            $cursors->decode($request->validated('page.after'), $workspace, $project), (int) $request->validated('page.size', 25));

        return response()->json([
            'data' => ['schema_version' => 1, 'changes' => $this->metadata($page->changes), 'latest_version' => $page->latestVersion,
                'has_more' => $page->hasMore, 'resync_required' => $page->resyncRequired],
            'meta' => ['request_id' => $request->attributes->get('music.request_id'),
                'next_cursor' => $page->resyncRequired ? null : $cursors->encode($workspace, $project, $page->nextCursor)],
            'links' => ['snapshot' => route('music.projects.api.show', ['workspace' => $workspace, 'project' => $project, 'version' => $page->latestVersion], false)],
        ]);
    }

    public function history(ReadSessionRequest $request, SessionMetadata $sessions, string $workspace, string $project): JsonResponse
    {
        $limit = (int) $request->validated('page.size', 25);
        $page = $sessions->history($request->user(), $workspace, $project, $limit, $request->cursor());

        return $this->response($request, $this->metadata($page->items), $page->nextCursor);
    }

    public function export(ReadSessionRequest $request, ProjectSessions $sessions, string $workspace, string $project): JsonResponse
    {
        return $this->response($request, json_decode($sessions->export($request->user(), $workspace, $project, $this->version($request)), true, flags: JSON_THROW_ON_ERROR));
    }

    public function preview(ReadSessionRequest $request, ProjectSessions $sessions, string $workspace, string $project): JsonResponse
    {
        return $this->response($request, $sessions->previewImport($request->user(), $workspace, $project, $request->validated('manifest')));
    }

    private function metadata(array $items): array
    {
        foreach ($items as &$item) {
            $item['created_at'] = (new DateTimeImmutable($item['created_at'], new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
        }

        return $items;
    }

    private function version(ReadSessionRequest $request): ?int
    {
        $version = $request->validated('version');

        return $version === null ? null : (int) $version;
    }

    private function response(ReadSessionRequest $request, mixed $data, ?int $next = null, ?string $changesCursor = null): JsonResponse
    {
        $meta = ['request_id' => $request->attributes->get('music.request_id'), 'next_cursor' => $next === null ? null : base64_encode((string) $next)];
        if ($changesCursor !== null) {
            $meta['changes_cursor'] = $changesCursor;
        }

        return response()->json(['data' => $data, 'meta' => $meta]);
    }
}
