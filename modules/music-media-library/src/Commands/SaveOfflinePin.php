<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibrary\Commands;

use Illuminate\Support\Facades\Validator;

final readonly class SaveOfflinePin
{
    public array $assetIds;

    public function __construct(public string $workspaceId, public string $pinId, array $assetIds, public int $expectedVersion, public string $commandKey, public ?string $expiresAt = null)
    {
        Validator::make(['workspace' => $workspaceId, 'pin' => $pinId, 'assets' => $assetIds, 'version' => $expectedVersion, 'key' => $commandKey, 'expires' => $expiresAt], [
            'workspace' => ['required', 'string', 'max:128'], 'pin' => ['required', 'uuid'],
            'assets' => ['array', 'list', 'max:10000'], 'assets.*' => ['required', 'uuid'],
            'version' => ['integer:strict', 'min:0', 'max:'.(PHP_INT_MAX - 1)], 'key' => ['required', 'string', 'max:128'],
            'expires' => ['nullable', 'date_format:Y-m-d\TH:i:s\Z'],
        ])->validate();
        $ids = array_values(array_unique($assetIds));
        sort($ids, SORT_STRING);
        $this->assetIds = $ids;
    }
}
