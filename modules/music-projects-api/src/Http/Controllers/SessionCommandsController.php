<?php

declare(strict_types=1);

namespace Liberu\Music\ProjectsApi\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Liberu\Music\Projects\Commands\SaveSession;
use Liberu\Music\Projects\Data\SessionDocument;
use Liberu\Music\Projects\Data\SessionRevision;
use Liberu\Music\Projects\Services\ProjectSessions;
use Liberu\Music\ProjectsApi\Http\Requests\ImportSessionRequest;
use Liberu\Music\ProjectsApi\Http\Requests\RecoverSessionRequest;
use Liberu\Music\ProjectsApi\Http\Requests\SaveCheckpointRequest;
use Liberu\Music\ProjectsApi\Http\Requests\SessionCommandRequest;

final class SessionCommandsController
{
    public function save(SaveCheckpointRequest $request, ProjectSessions $sessions, string $workspace, string $project): JsonResponse
    {
        $revision = $sessions->save($request->user(), new SaveSession($workspace, $project, $request->validated('title'),
            SessionDocument::fromArray($request->validated('document')), $request->expectedVersion(), $request->commandKey(),
            $request->validated('autosave', false), $request->validated('template', false)));

        return $this->response($request, $revision);
    }

    public function recover(RecoverSessionRequest $request, ProjectSessions $sessions, string $workspace, string $project): JsonResponse
    {
        return $this->response($request, $sessions->recover($request->user(), $workspace, $project,
            $request->validated('source_version'), $request->expectedVersion(), $request->commandKey()));
    }

    public function import(ImportSessionRequest $request, ProjectSessions $sessions, string $workspace, string $project): JsonResponse
    {
        return $this->response($request, $sessions->import($request->user(), $workspace, $project,
            $request->validated('manifest'), $request->commandKey()));
    }

    private function response(SessionCommandRequest $request, SessionRevision $revision): JsonResponse
    {
        return response()->json(['data' => $revision, 'meta' => ['request_id' => $request->attributes->get('music.request_id')]], 201)
            ->header('ETag', '"'.$revision->version.'"')
            ->header('Location', route('music.projects.api.show', ['workspace' => $revision->workspaceId, 'project' => $revision->projectId, 'version' => $revision->version], false));
    }
}
