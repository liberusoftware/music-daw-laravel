<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibraryApi\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Liberu\Music\MediaLibrary\Commands\RequestArchiveImport;
use Liberu\Music\MediaLibrary\Data\ArchiveImportTask;
use Liberu\Music\MediaLibrary\Services\ArchiveImports;
use Liberu\Music\MediaLibraryApi\Http\Requests\ArchiveImportRequest;
use Liberu\Music\MediaLibraryApi\Support\CommandHeaders;

final class ArchiveImportsController
{
    public function request(ArchiveImportRequest $request, CommandHeaders $headers, ArchiveImports $imports, string $workspace, string $pack, string $task): JsonResponse
    {
        $headers->key($request, $task);
        $result = $imports->request($request->user(), new RequestArchiveImport($workspace, $task, $request->validated('input_id'), $pack, $request->validated('asset_map')));

        return $this->response($request, $result, in_array($result->status, ['queued', 'running'], true) ? 202 : 200);
    }

    public function show(Request $request, ArchiveImports $imports, string $workspace, string $task): JsonResponse
    {
        return $this->response($request, $imports->get($request->user(), $workspace, $task));
    }

    public function cancel(Request $request, CommandHeaders $headers, ArchiveImports $imports, string $workspace, string $task): JsonResponse
    {
        $headers->key($request, $task);
        $result = $imports->cancel($request->user(), $workspace, $task);

        return $this->response($request, $result, $result->status === 'running' ? 202 : 200);
    }

    public function result(Request $request, ArchiveImports $imports, string $workspace, string $task): JsonResponse
    {
        $status = $imports->get($request->user(), $workspace, $task);
        if (in_array($status->status, ['queued', 'running'], true)) {
            return $this->response($request, $status, 202)->header('Retry-After', '2');
        }
        $pack = $imports->result($request->user(), $workspace, $task);
        abort_if($pack === null, 409);

        return response()->json(['data' => $pack, 'meta' => ['request_id' => $request->attributes->get('music.request_id'), 'next_cursor' => null],
            'links' => ['pack' => route('music.media.api.pack', ['workspace' => $workspace, 'pack' => $pack->packId], false)]])->header('ETag', '"'.$pack->version.'"');
    }

    private function response(Request $request, ArchiveImportTask $task, int $status = 200): JsonResponse
    {
        $url = route('music.media.api.archive-import', ['workspace' => $task->workspaceId, 'task' => $task->id], false);

        return response()->json(['data' => $task, 'meta' => ['request_id' => $request->attributes->get('music.request_id'), 'next_cursor' => null],
            'links' => ['status' => $url, 'result' => $url.'/result', 'cancel' => $url]], $status)->header('Location', $url);
    }
}
