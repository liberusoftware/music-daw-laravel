<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibraryApi\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Liberu\Music\MediaLibrary\Commands\SaveMediaPack;
use Liberu\Music\MediaLibrary\Data\MediaPack;
use Liberu\Music\MediaLibrary\Services\MediaPacks;
use Liberu\Music\MediaLibraryApi\Http\Requests\ImportPackRequest;
use Liberu\Music\MediaLibraryApi\Http\Requests\ReadPackRequest;
use Liberu\Music\MediaLibraryApi\Http\Requests\SavePackRequest;
use Liberu\Music\MediaLibraryApi\Support\CommandHeaders;

final class PacksController
{
    public function show(ReadPackRequest $request, MediaPacks $packs, string $workspace, string $pack): JsonResponse
    {
        return $this->response($request, $packs->get($request->user(), $workspace, $pack, $request->version()));
    }

    public function save(SavePackRequest $request, CommandHeaders $headers, MediaPacks $packs, string $workspace, string $pack): JsonResponse
    {
        $key = $headers->key($request);
        $version = $headers->version($request);

        return $this->response($request, $packs->save($request->user(), new SaveMediaPack($workspace, $pack,
            $request->validated('name'), $request->validated('asset_ids'), $request->validated('rights'), $version, $key)));
    }

    public function history(ReadPackRequest $request, MediaPacks $packs, string $workspace, string $pack): JsonResponse
    {
        $limit = (int) $request->validated('page.limit', 25);
        $before = $request->validated('page.before');
        $items = $packs->history($request->user(), $workspace, $pack, $limit, $before === null ? null : (int) $before);
        $next = count($items) === $limit ? (string) $items[array_key_last($items)]->version : null;

        return response()->json(['data' => $items, 'meta' => ['request_id' => $request->attributes->get('music.request_id'), 'next_cursor' => $next],
            'links' => $next === null ? [] : ['next' => route('music.media.api.pack-history', ['workspace' => $workspace, 'pack' => $pack,
                'page' => ['limit' => $limit, 'before' => $next]], false)]]);
    }

    public function export(ReadPackRequest $request, MediaPacks $packs, string $workspace, string $pack): JsonResponse
    {
        $manifest = json_decode($packs->export($request->user(), $workspace, $pack, $request->version()), true, flags: JSON_THROW_ON_ERROR);

        return response()->json(['data' => $manifest, 'meta' => ['request_id' => $request->attributes->get('music.request_id'), 'next_cursor' => null], 'links' => []]);
    }

    public function preview(ImportPackRequest $request, MediaPacks $packs, string $workspace, string $pack): JsonResponse
    {
        return $this->response($request, $packs->previewImport($request->user(), $workspace, $pack, $request->manifest(), $request->validated('asset_map')));
    }

    public function import(ImportPackRequest $request, CommandHeaders $headers, MediaPacks $packs, string $workspace, string $pack): JsonResponse
    {
        $key = $headers->key($request);
        if ($headers->version($request) !== 0) {
            throw ValidationException::withMessages(['headers.If-Match' => 'Manifest import creates a new pack; use the quoted version zero.']);
        }

        return $this->response($request, $packs->import($request->user(), $workspace, $pack, $request->manifest(), $request->validated('asset_map'), $key));
    }

    private function response(Request $request, MediaPack $pack): JsonResponse
    {
        return response()->json(['data' => $pack, 'meta' => ['request_id' => $request->attributes->get('music.request_id'), 'next_cursor' => null],
            'links' => ['pack' => route('music.media.api.pack', ['workspace' => $pack->workspaceId, 'pack' => $pack->packId], false)]])->header('ETag', '"'.$pack->version.'"');
    }
}
