<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibrary\Commands;

use Illuminate\Support\Facades\Validator;

final readonly class ProcessAsset
{
    public function __construct(public string $workspaceId, public string $taskId, public string $uploadId, public ?string $assetId = null)
    {
        Validator::make(['workspace' => $workspaceId, 'task' => $taskId, 'upload' => $uploadId, 'asset' => $assetId], [
            'workspace' => ['required', 'string', 'max:128'], 'task' => ['required', 'uuid'], 'upload' => ['required', 'uuid'], 'asset' => ['nullable', 'uuid'],
        ])->validate();
        if ($assetId !== null) {
            new RestoreAsset($workspaceId, $assetId, $uploadId, $taskId);
        }
    }
}
