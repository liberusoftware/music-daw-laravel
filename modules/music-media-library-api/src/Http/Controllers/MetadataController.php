<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibraryApi\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Liberu\Music\MediaLibrary\Commands\UpdateAssetMetadata;
use Liberu\Music\MediaLibrary\Services\MediaCatalog;
use Liberu\Music\MediaLibraryApi\Http\Requests\MetadataRequest;
use Liberu\Music\MediaLibraryApi\Support\CommandHeaders;

final class MetadataController
{
    public function update(MetadataRequest $request, CommandHeaders $headers, MediaCatalog $catalog, string $workspace, string $asset): JsonResponse
    {
        $key = $headers->key($request);
        $version = $headers->version($request);
        $metadata = $catalog->update($request->user(), new UpdateAssetMetadata($workspace, $asset, $request->validated('name'), $request->validated('tags'), $version, $key));

        return response()->json(['data' => $metadata, 'meta' => ['request_id' => $request->attributes->get('music.request_id'), 'next_cursor' => null],
            'links' => ['asset' => route('music.media.api.show', ['workspace' => $workspace, 'asset' => $asset], false)]])->header('ETag', '"'.$metadata->version.'"');
    }
}
