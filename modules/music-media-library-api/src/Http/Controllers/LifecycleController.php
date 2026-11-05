<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibraryApi\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Liberu\Music\MediaLibrary\Commands\ListTrashedAssets;
use Liberu\Music\MediaLibrary\Commands\TrashAssets;
use Liberu\Music\MediaLibrary\Services\AssetLifecycle;
use Liberu\Music\MediaLibraryApi\Http\Requests\BrowseTrashRequest;
use Liberu\Music\MediaLibraryApi\Http\Requests\TrashRequest;
use Liberu\Music\MediaLibraryApi\Support\CatalogCursor;
use Liberu\Music\MediaLibraryApi\Support\CommandHeaders;

final class LifecycleController
{
    public function browse(BrowseTrashRequest $request, CatalogCursor $cursors, AssetLifecycle $lifecycle, string $workspace): JsonResponse
    {
        $filters = ['operation' => 'trash', 'limit' => (int) $request->validated('page.size', 25)];
        $actor = (string) $request->user()->getAuthIdentifier();
        $after = $cursors->decode($request->validated('page.after'), $actor, $workspace, $filters);
        $page = $lifecycle->list($request->user(), new ListTrashedAssets($workspace, $filters['limit'], $after));

        return response()->json(['data' => $page->items, 'meta' => ['request_id' => $request->attributes->get('music.request_id'),
            'next_cursor' => $page->nextAssetId === null ? null : $cursors->encode($actor, $workspace, $filters, $page->nextAssetId)], 'links' => []]);
    }

    public function trash(TrashRequest $request, CommandHeaders $headers, AssetLifecycle $lifecycle, string $workspace, string $command): JsonResponse
    {
        $headers->key($request, $command);
        $change = $lifecycle->trash($request->user(), new TrashAssets($workspace, $request->validated('asset_ids'), 'trash:'.$command, $request->validated('grace_hours', 24)));

        return response()->json(['data' => $change, 'meta' => ['request_id' => $request->attributes->get('music.request_id'), 'next_cursor' => null], 'links' => []]);
    }
}
