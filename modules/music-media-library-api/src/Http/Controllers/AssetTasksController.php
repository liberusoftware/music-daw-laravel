<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibraryApi\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Liberu\Music\MediaLibrary\Commands\ProcessAsset;
use Liberu\Music\MediaLibrary\Data\AssetTask;
use Liberu\Music\MediaLibrary\Services\AssetProcessing;
use Liberu\Music\MediaLibraryApi\Http\Requests\AssetTaskRequest;
use Liberu\Music\MediaLibraryApi\Support\CommandHeaders;

final class AssetTasksController
{
    public function request(AssetTaskRequest $request, CommandHeaders $headers, AssetProcessing $jobs, string $workspace, string $task, ?string $asset = null): JsonResponse
    {
        $headers->key($request, $task);
        $result = $jobs->request($request->user(), new ProcessAsset($workspace, $task, $request->uploadId(), $asset));

        return $this->response($request, $result, $result->status === 'queued' ? 202 : 200);
    }

    public function restore(AssetTaskRequest $request, CommandHeaders $headers, AssetProcessing $jobs, string $workspace, string $asset, string $task): JsonResponse
    {
        return $this->request($request, $headers, $jobs, $workspace, $task, $asset);
    }

    public function show(Request $request, AssetProcessing $jobs, string $workspace, string $task): JsonResponse
    {
        $result = $jobs->get($request->user(), $workspace, $task);
        $this->scope($request, $result);

        return $this->response($request, $result);
    }

    public function cancel(Request $request, CommandHeaders $headers, AssetProcessing $jobs, string $workspace, string $task): JsonResponse
    {
        $headers->key($request, $task);
        $this->scope($request, $jobs->get($request->user(), $workspace, $task));

        return $this->response($request, $jobs->cancel($request->user(), $workspace, $task));
    }

    public function result(Request $request, AssetProcessing $jobs, string $workspace, string $task): JsonResponse
    {
        $status = $jobs->get($request->user(), $workspace, $task);
        $this->scope($request, $status);
        if ($status->status === 'queued') {
            return $this->response($request, $status, 202)->header('Retry-After', '2');
        }
        abort_if($status->status !== 'ready', 409);
        $asset = $jobs->result($request->user(), $workspace, $task);
        abort_if($asset === null, 409);

        return response()->json(['data' => $asset->reference(), 'meta' => ['request_id' => $request->attributes->get('music.request_id'), 'next_cursor' => null],
            'links' => ['asset' => route('music.media.api.show', ['workspace' => $workspace, 'asset' => $asset->id], false)]]);
    }

    private function scope(Request $request, AssetTask $task): void
    {
        abort_unless($request->user()->tokenCan($task->restoredAssetId === null ? 'music.media.upload' : 'music.media.restore'), 403);
    }

    private function response(Request $request, AssetTask $task, int $status = 200): JsonResponse
    {
        $url = route('music.media.api.asset-task', ['workspace' => $task->workspaceId, 'task' => $task->id], false);

        return response()->json(['data' => $task, 'meta' => ['request_id' => $request->attributes->get('music.request_id'), 'next_cursor' => null],
            'links' => ['status' => $url, 'result' => $url.'/result', 'cancel' => $url]], $status)->header('Location', $url);
    }
}
