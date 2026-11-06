<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibraryApi\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Liberu\Music\MediaLibrary\Commands\RequestArchiveExport;
use Liberu\Music\MediaLibrary\Data\ArchiveExportTask;
use Liberu\Music\MediaLibrary\Services\ArchiveExports;
use Liberu\Music\MediaLibraryApi\Http\Requests\ArchiveExportRequest;
use Liberu\Music\MediaLibraryApi\Support\CommandHeaders;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ArchiveExportsController
{
    public function request(ArchiveExportRequest $request, CommandHeaders $headers, ArchiveExports $exports, string $workspace, string $pack, string $task): JsonResponse
    {
        $headers->key($request, $task);
        $result = $exports->request($request->user(), new RequestArchiveExport($workspace, $task, $pack, $request->validated('version')));

        return $this->response($request, $result, $result->status === 'queued' ? 202 : 200);
    }

    public function show(Request $request, ArchiveExports $exports, string $workspace, string $task): JsonResponse
    {
        return $this->response($request, $exports->get($request->user(), $workspace, $task));
    }

    public function cancel(Request $request, CommandHeaders $headers, ArchiveExports $exports, string $workspace, string $task): JsonResponse
    {
        $headers->key($request, $task);

        return $this->response($request, $exports->cancel($request->user(), $workspace, $task));
    }

    public function result(Request $request, ArchiveExports $exports, string $workspace, string $task): JsonResponse
    {
        $status = $exports->get($request->user(), $workspace, $task);
        if ($status->status === 'queued') {
            return $this->response($request, $status, 202)->header('Retry-After', '2');
        }
        $transfer = $exports->result($request->user(), $workspace, $task);
        abort_if($transfer === null, 409);

        return response()->json(['data' => $transfer, 'meta' => ['request_id' => $request->attributes->get('music.request_id'), 'next_cursor' => null],
            'links' => ['download_url' => route('music.media.api.archive-download-url', ['workspace' => $workspace, 'task' => $task], false)]]);
    }

    public function issue(Request $request, ArchiveExports $exports, string $workspace, string $task): JsonResponse
    {
        $transfer = $exports->result($request->user(), $workspace, $task);
        abort_if($transfer === null, 409);
        $status = $exports->get($request->user(), $workspace, $task);
        $expires = CarbonImmutable::now()->addSeconds(60)->min(CarbonImmutable::parse($status->expiresAt));
        $url = URL::temporarySignedRoute('music.media.api.archive-download', $expires, ['workspace' => $workspace, 'task' => $task,
            'actor' => (string) $request->user()->getAuthIdentifier(), 'sha256' => $transfer->sha256]);

        return response()->json(['data' => ['schema_version' => 1, 'url' => $url, 'expires_at' => $expires->toISOString(), 'sha256' => $transfer->sha256, 'bytes' => $transfer->bytes],
            'meta' => ['request_id' => $request->attributes->get('music.request_id'), 'next_cursor' => null], 'links' => []]);
    }

    public function download(Request $request, ArchiveExports $exports, string $workspace, string $task): StreamedResponse
    {
        abort_unless($request->hasValidSignature() && $request->query('actor') === (string) $request->user()->getAuthIdentifier(), 403);
        abort_if($request->headers->has('Range'), 416, headers: ['Accept-Ranges' => 'none']);
        $transfer = $exports->result($request->user(), $workspace, $task);
        abort_if($transfer === null, 409);
        abort_unless($request->query('sha256') === $transfer->sha256, 403);
        $stream = $exports->open($request->user(), $workspace, $task);

        return response()->streamDownload(function () use ($stream): void {
            try {
                fpassthru($stream);
            } finally {
                fclose($stream);
            }
        }, $task.'.zip', ['Content-Type' => 'application/zip', 'Content-Length' => (string) $transfer->bytes, 'ETag' => '"'.$transfer->sha256.'"',
            'Accept-Ranges' => 'none', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function response(Request $request, ArchiveExportTask $task, int $status = 200): JsonResponse
    {
        $url = route('music.media.api.archive-export', ['workspace' => $task->workspaceId, 'task' => $task->id], false);

        return response()->json(['data' => $task, 'meta' => ['request_id' => $request->attributes->get('music.request_id'), 'next_cursor' => null],
            'links' => ['status' => $url, 'result' => $url.'/result', 'cancel' => $url]], $status)->header('Location', $url);
    }
}
