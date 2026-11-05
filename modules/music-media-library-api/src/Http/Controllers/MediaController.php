<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibraryApi\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Liberu\Music\MediaLibrary\Commands\BrowseAssets;
use Liberu\Music\MediaLibrary\Commands\RequestWaveform;
use Liberu\Music\MediaLibrary\Contracts\MediaAuthorizer;
use Liberu\Music\MediaLibrary\Data\WaveformTask;
use Liberu\Music\MediaLibrary\Services\MediaCatalog;
use Liberu\Music\MediaLibrary\Services\UploadBudgets;
use Liberu\Music\MediaLibrary\Services\WaveformProcessing;
use Liberu\Music\MediaLibraryApi\Http\Requests\BrowseMediaRequest;
use Liberu\Music\MediaLibraryApi\Http\Requests\WaveformRequest;
use Liberu\Music\MediaLibraryApi\Support\CatalogCursor;

final class MediaController
{
    public function capabilities(Request $request, MediaAuthorizer $access, string $workspace): JsonResponse
    {
        $access->authorize($request->user(), $workspace, '*', 'list');

        return $this->response($request, ['schema_version' => 1, 'operations' => ['browse', 'read', 'upload_budget', 'waveform_request', 'waveform_status', 'waveform_result', 'waveform_cancel'],
            'unsupported' => ['asset_transfer', 'upload_verification_jobs', 'metadata_edits', 'packs', 'lifecycle_commands', 'native_audio_execution']]);
    }

    public function index(BrowseMediaRequest $request, MediaCatalog $catalog, CatalogCursor $cursors, string $workspace): JsonResponse
    {
        $filters = $request->filters();
        $actor = (string) $request->user()->getAuthIdentifier();
        $before = $cursors->decode($request->validated('page.after'), $actor, $workspace, $filters);
        $page = $catalog->browse($request->user(), new BrowseAssets($workspace, $filters['query'], $filters['tags'], $filters['sha256'], $filters['limit'], $before));

        return $this->response($request, $page->items, nextCursor: $page->nextAssetId === null ? null : $cursors->encode($actor, $workspace, $filters, $page->nextAssetId));
    }

    public function show(Request $request, MediaCatalog $catalog, string $workspace, string $asset): JsonResponse
    {
        return $this->response($request, $catalog->get($request->user(), $workspace, $asset));
    }

    public function budget(Request $request, UploadBudgets $budgets, string $workspace): JsonResponse
    {
        return $this->response($request, $budgets->get($request->user(), $workspace));
    }

    public function task(Request $request, WaveformProcessing $jobs, string $workspace, string $task): JsonResponse
    {
        return $this->taskResponse($request, $jobs->get($request->user(), $workspace, $task));
    }

    public function process(WaveformRequest $request, WaveformProcessing $jobs, string $workspace, string $task): JsonResponse
    {
        $request->requireIdempotency($task);
        $result = $jobs->request($request->user(), new RequestWaveform($workspace, $task, $request->validated('asset_id'), $request->validated('sample_frames_per_peak', 1024)));

        return $this->taskResponse($request, $result, $result->status === 'queued' ? 202 : 200);
    }

    public function cancel(WaveformRequest $request, WaveformProcessing $jobs, string $workspace, string $task): JsonResponse
    {
        $request->requireIdempotency($task);

        return $this->taskResponse($request, $jobs->cancel($request->user(), $workspace, $task));
    }

    public function result(Request $request, WaveformProcessing $jobs, string $workspace, string $task): JsonResponse
    {
        $status = $jobs->get($request->user(), $workspace, $task);
        if ($status->status === 'queued') {
            return $this->taskResponse($request, $status, 202)->header('Retry-After', '2');
        }
        abort_if($status->status !== 'ready', 409);
        $waveform = $jobs->result($request->user(), $workspace, $task);
        abort_if($waveform === null, 410);

        return $this->response($request, $waveform);
    }

    private function taskResponse(Request $request, WaveformTask $task, int $status = 200): JsonResponse
    {
        $url = route('music.media.api.task', ['workspace' => $task->workspaceId, 'task' => $task->id], false);

        return $this->response($request, $task, $status, links: ['status' => $url, 'result' => $url.'/result', 'cancel' => $url])->header('Location', $url);
    }

    private function response(Request $request, mixed $data, int $status = 200, ?string $nextCursor = null, array $links = []): JsonResponse
    {
        return response()->json(['data' => $data, 'meta' => ['request_id' => $request->attributes->get('music.request_id'), 'next_cursor' => $nextCursor], 'links' => (object) $links], $status);
    }
}
