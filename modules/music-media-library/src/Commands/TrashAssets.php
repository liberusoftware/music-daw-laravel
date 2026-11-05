<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibrary\Commands;

use Illuminate\Support\Facades\Validator;

final readonly class TrashAssets
{
    public array $assetIds;

    public function __construct(public string $workspaceId, array $assetIds, public string $commandKey, public int $graceHours = 24)
    {
        Validator::make(['workspace' => $workspaceId, 'assets' => $assetIds, 'command' => $commandKey, 'grace' => $graceHours], [
            'workspace' => ['required', 'string', 'max:128'], 'assets' => ['required', 'array', 'list', 'max:100'],
            'assets.*' => ['required', 'uuid', 'distinct'], 'command' => ['required', 'string', 'max:200'], 'grace' => ['integer:strict', 'between:24,8760'],
        ])->validate();
        sort($assetIds, SORT_STRING);
        $this->assetIds = $assetIds;
    }
}
