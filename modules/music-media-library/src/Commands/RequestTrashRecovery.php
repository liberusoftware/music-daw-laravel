<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibrary\Commands;

use Illuminate\Support\Facades\Validator;

final readonly class RequestTrashRecovery
{
    public function __construct(public string $workspaceId, public string $taskId, public string $assetId)
    {
        Validator::make(['workspace' => $workspaceId, 'task' => $taskId, 'asset' => $assetId], [
            'workspace' => ['required', 'string', 'max:128'], 'task' => ['required', 'uuid'], 'asset' => ['required', 'uuid'],
        ])->validate();
    }
}
