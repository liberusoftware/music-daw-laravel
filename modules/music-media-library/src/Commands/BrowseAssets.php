<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibrary\Commands;

use Illuminate\Support\Facades\Validator;

final readonly class BrowseAssets
{
    public SearchAssets $filters;

    public function __construct(public string $workspaceId, string $query = '', array $tags = [], ?string $sha256 = null, public int $limit = 25, public ?string $beforeAssetId = null)
    {
        $this->filters = new SearchAssets($workspaceId, $query, $tags, $sha256, 1, $limit);
        Validator::make(['cursor' => $beforeAssetId], ['cursor' => ['nullable', 'uuid']])->validate();
    }
}
