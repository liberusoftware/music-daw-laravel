<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibraryApi\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Liberu\Music\MediaLibrary\Services\MediaDownloads;
use Liberu\Music\MediaLibrary\Services\MediaUploads;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class DownloadsController
{
    public function issue(Request $request, MediaDownloads $downloads, MediaUploads $uploads, string $workspace, string $asset): JsonResponse
    {
        $downloads->authorize($request->user(), $workspace, $asset);
        $ready = $uploads->get($request->user(), $workspace, $asset);
        $expires = now()->addSeconds(60);
        $url = URL::temporarySignedRoute('music.media.api.download', $expires, ['workspace' => $workspace, 'asset' => $asset,
            'actor' => (string) $request->user()->getAuthIdentifier(), 'sha256' => $ready->metadata['sha256']]);

        return response()->json(['data' => ['schema_version' => 1, 'url' => $url, 'expires_at' => $expires->toISOString(),
            'sha256' => $ready->metadata['sha256'], 'bytes' => $ready->metadata['bytes']],
            'meta' => ['request_id' => $request->attributes->get('music.request_id'), 'next_cursor' => null], 'links' => []]);
    }

    public function download(Request $request, MediaDownloads $downloads, MediaUploads $uploads, string $workspace, string $asset): StreamedResponse
    {
        abort_unless($request->hasValidSignature() && $request->query('actor') === (string) $request->user()->getAuthIdentifier(), 403);
        abort_if($request->headers->has('Range'), 416, headers: ['Accept-Ranges' => 'none']);
        $ready = $uploads->get($request->user(), $workspace, $asset);
        abort_unless($request->query('sha256') === $ready->metadata['sha256'], 403);
        $stream = $downloads->open($request->user(), $workspace, $asset);

        return response()->streamDownload(function () use ($stream): void {
            try {
                fpassthru($stream);
            } finally {
                fclose($stream);
            }
        }, $asset.'.wav', ['Content-Type' => 'audio/wav', 'Content-Length' => (string) $ready->metadata['bytes'],
            'ETag' => '"'.$ready->metadata['sha256'].'"', 'Accept-Ranges' => 'none', 'X-Content-Type-Options' => 'nosniff']);
    }
}
