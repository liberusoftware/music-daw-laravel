<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibrary\Commands;

use Illuminate\Support\Facades\Validator;

final readonly class SaveMediaPack
{
    public array $assetIds;

    public array $rights;

    public function __construct(public string $workspaceId, public string $packId, public string $name, array $assetIds, array $rights, public int $expectedVersion, public string $commandKey)
    {
        Validator::make(['workspace' => $workspaceId, 'pack' => $packId, 'name' => $name, 'assets' => $assetIds, 'rights' => $rights, 'version' => $expectedVersion, 'key' => $commandKey], [
            'workspace' => ['required', 'string', 'max:128'], 'pack' => ['required', 'uuid'], 'name' => ['required', 'string', 'max:255'],
            'assets' => ['required', 'array', 'list', 'max:1000'], 'assets.*' => ['required', 'uuid'],
            'rights' => ['required', 'array:source,license,attribution'],
            'rights.source' => ['required', 'string', 'max:2048'], 'rights.license' => ['required', 'string', 'max:2048'], 'rights.attribution' => ['required', 'string', 'max:2048'],
            'version' => ['integer:strict', 'between:0,'.(PHP_INT_MAX - 1)], 'key' => ['required', 'string', 'max:128'],
        ])->validate();
        $ids = array_values(array_unique($assetIds));
        sort($ids, SORT_STRING);
        $this->assetIds = $ids;
        $this->rights = ['source' => $rights['source'], 'license' => $rights['license'], 'attribution' => $rights['attribution']];
    }
}
