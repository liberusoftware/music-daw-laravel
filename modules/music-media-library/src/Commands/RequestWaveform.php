<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibrary\Commands;

use Illuminate\Support\Facades\Validator;

final readonly class RequestWaveform
{
    public function __construct(public string $workspaceId, public string $taskId, public string $assetId, public int $framesPerPeak = 1024)
    {
        Validator::make(['workspace' => $workspaceId, 'task' => $taskId, 'asset' => $assetId, 'window' => $framesPerPeak], [
            'workspace' => ['required', 'string', 'max:128'], 'task' => ['required', 'uuid'], 'asset' => ['required', 'uuid'],
            'window' => ['integer:strict', 'between:1,1048576'],
        ])->validate();
    }
}
