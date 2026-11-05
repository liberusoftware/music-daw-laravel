<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibrary\Commands;

use Illuminate\Support\Facades\Validator;

final readonly class RequestArchiveExport
{
    public function __construct(public string $workspaceId, public string $taskId, public string $packId, public int $version)
    {
        Validator::make(['workspace' => $workspaceId, 'task' => $taskId, 'pack' => $packId, 'version' => $version], [
            'workspace' => ['required', 'string', 'max:128'], 'task' => ['required', 'uuid'], 'pack' => ['required', 'uuid'],
            'version' => ['integer:strict', 'min:1'],
        ])->validate();
    }
}
