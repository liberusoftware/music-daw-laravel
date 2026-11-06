<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibraryApi\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Liberu\Music\MediaLibrary\Commands\StartUpload;
use Liberu\Music\MediaLibrary\Data\UploadProgress;
use Liberu\Music\MediaLibrary\Services\MediaUploads;
use Liberu\Music\MediaLibraryApi\Http\Requests\ArchiveInputRequest;
use Liberu\Music\MediaLibraryApi\Http\Requests\ChunkRequest;
use Liberu\Music\MediaLibraryApi\Support\CommandHeaders;

final class ArchiveInputsController
{
    public function declare(ArchiveInputRequest $request, CommandHeaders $headers, MediaUploads $uploads, string $workspace, string $upload): JsonResponse
    {
        $headers->key($request, $upload);
        $progress = $uploads->start($request->user(), new StartUpload($workspace, $upload, $request->validated('name'), $request->validated('bytes'), $request->validated('sha256'), $request->validated('provenance', []), purpose: 'archive'));

        return $this->response($request, $progress);
    }

    public function status(Request $request, MediaUploads $uploads, string $workspace, string $upload): JsonResponse
    {
        return $this->response($request, $uploads->status($request->user(), $workspace, $upload, 'archive'));
    }

    public function cancel(Request $request, CommandHeaders $headers, MediaUploads $uploads, string $workspace, string $upload): JsonResponse
    {
        $headers->key($request, $upload);

        return $this->response($request, $uploads->cancel($request->user(), $workspace, $upload, 'archive'));
    }

    public function append(ChunkRequest $request, CommandHeaders $headers, MediaUploads $uploads, string $workspace, string $upload, string $offset): JsonResponse
    {
        $checksum = $request->checksum();
        $headers->key($request, $upload.':'.$offset.':'.$checksum);

        return $this->response($request, $uploads->append($request->user(), $workspace, $upload, (int) $request->validated('offset_bytes'), $request->bytes(), 'archive'));
    }

    private function response(Request $request, UploadProgress $progress): JsonResponse
    {
        return response()->json(['data' => ['schema_version' => 1, 'upload_id' => $progress->id, 'workspace_id' => $progress->workspaceId,
            'status' => $progress->status, 'received_bytes' => $progress->receivedBytes, 'total_bytes' => $progress->totalBytes],
            'meta' => ['request_id' => $request->attributes->get('music.request_id'), 'next_cursor' => null], 'links' => (object) []]);
    }
}
