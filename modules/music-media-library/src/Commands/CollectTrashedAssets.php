<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibrary\Commands;

use Illuminate\Support\Facades\Validator;

final readonly class CollectTrashedAssets
{
    public function __construct(public string $workspaceId, public int $limit = 100, public ?string $afterAssetId = null, public bool $dryRun = true)
    {
        Validator::make(['workspace' => $workspaceId, 'limit' => $limit, 'cursor' => $afterAssetId], [
            'workspace' => ['required', 'string', 'max:128'], 'limit' => ['integer:strict', 'between:1,100'], 'cursor' => ['nullable', 'uuid'],
        ])->validate();
    }
}
