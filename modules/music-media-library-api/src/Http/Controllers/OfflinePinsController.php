<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibraryApi\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Liberu\Music\MediaLibrary\Commands\SaveOfflinePin;
use Liberu\Music\MediaLibrary\Data\OfflinePin;
use Liberu\Music\MediaLibrary\Services\OfflinePins;
use Liberu\Music\MediaLibraryApi\Http\Requests\SaveOfflinePinRequest;
use Liberu\Music\MediaLibraryApi\Support\CommandHeaders;

final class OfflinePinsController
{
    public function show(Request $request, OfflinePins $pins, string $workspace, string $pin): JsonResponse
    {
        return $this->response($request, $pins->get($request->user(), $workspace, $pin));
    }

    public function save(SaveOfflinePinRequest $request, CommandHeaders $headers, OfflinePins $pins, string $workspace, string $pin): JsonResponse
    {
        $key = $headers->key($request);
        $version = $headers->version($request);

        return $this->response($request, $pins->save($request->user(), new SaveOfflinePin($workspace, $pin,
            $request->validated('asset_ids'), $version, $key, $request->validated('expires_at'))));
    }

    private function response(Request $request, OfflinePin $pin): JsonResponse
    {
        return response()->json(['data' => $pin, 'meta' => ['request_id' => $request->attributes->get('music.request_id'), 'next_cursor' => null],
            'links' => ['pin' => route('music.media.api.pin', ['workspace' => $pin->workspaceId, 'pin' => $pin->pinId], false)]])->header('ETag', '"'.$pin->version.'"');
    }
}
