<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibrary\Commands;

use Illuminate\Support\Facades\Validator;

final readonly class RecoverTrashedAsset
{
    public function __construct(public string $workspaceId, public string $assetId, public string $commandKey)
    {
        Validator::make(['workspace' => $workspaceId, 'asset' => $assetId, 'command' => $commandKey], [
            'workspace' => ['required', 'string', 'max:128'], 'asset' => ['required', 'uuid'], 'command' => ['required', 'string', 'max:200'],
        ])->validate();
    }
}
