<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibraryApi\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Liberu\Music\MediaLibrary\Commands\RequestTrashRecovery;
use Liberu\Music\MediaLibrary\Data\TrashRecoveryTask;
use Liberu\Music\MediaLibrary\Services\TrashRecovery;
use Liberu\Music\MediaLibraryApi\Support\CommandHeaders;

final class TrashRecoveryController
{
    public function request(Request $request, CommandHeaders $headers, TrashRecovery $jobs, string $workspace, string $asset, string $task): JsonResponse
    {
        $headers->key($request, $task);
        $result = $jobs->request($request->user(), new RequestTrashRecovery($workspace, $task, $asset));

        return $this->response($request, $result, $result->status === 'queued' ? 202 : 200);
    }

    public function show(Request $request, TrashRecovery $jobs, string $workspace, string $task): JsonResponse
    {
        $result = $jobs->get($request->user(), $workspace, $task);

        return $this->response($request, $result);
    }

    public function cancel(Request $request, CommandHeaders $headers, TrashRecovery $jobs, string $workspace, string $task): JsonResponse
    {
        $headers->key($request, $task);

        return $this->response($request, $jobs->cancel($request->user(), $workspace, $task));
    }

    public function result(Request $request, TrashRecovery $jobs, string $workspace, string $task): JsonResponse
    {
        $status = $jobs->get($request->user(), $workspace, $task);
        if ($status->status === 'queued') {
            return $this->response($request, $status, 202)->header('Retry-After', '2');
        }
        abort_if($status->status !== 'ready', 409);
        $asset = $jobs->result($request->user(), $workspace, $task);
        abort_if($asset === null, 409);

        return response()->json(['data' => $asset->reference(), 'meta' => ['request_id' => $request->attributes->get('music.request_id'), 'next_cursor' => null],
            'links' => ['asset' => route('music.media.api.show', ['workspace' => $workspace, 'asset' => $asset->id], false)]]);
    }

    private function response(Request $request, TrashRecoveryTask $task, int $status = 200): JsonResponse
    {
        $url = route('music.media.api.trash-recovery', ['workspace' => $task->workspaceId, 'task' => $task->id], false);

        return response()->json(['data' => $task, 'meta' => ['request_id' => $request->attributes->get('music.request_id'), 'next_cursor' => null],
            'links' => ['status' => $url, 'result' => $url.'/result', 'cancel' => $url]], $status)->header('Location', $url);
    }
}
