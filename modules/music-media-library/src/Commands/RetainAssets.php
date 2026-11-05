<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibrary\Commands;

use Illuminate\Support\Facades\Validator;

final readonly class RetainAssets
{
    public array $assetIds;

    public function __construct(public string $workspaceId, public string $referenceId, array $assetIds, public string $kind = 'session-revision')
    {
        Validator::make(['workspace' => $workspaceId, 'reference' => $referenceId, 'assets' => $assetIds, 'kind' => $kind], [
            'workspace' => ['required', 'string', 'max:128'], 'reference' => ['required', 'string', 'max:200'],
            'assets' => ['array', 'list', 'max:10000'], 'assets.*' => ['required', 'uuid'],
            'kind' => ['required', 'in:session-revision,offline-pin,pack'],
        ])->validate();
        $ids = array_values(array_unique($assetIds));
        sort($ids, SORT_STRING);
        $this->assetIds = $ids;
    }
}
