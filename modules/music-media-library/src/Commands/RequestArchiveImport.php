<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibrary\Commands;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final readonly class RequestArchiveImport
{
    public array $assetMap;

    public function __construct(public string $workspaceId, public string $taskId, public string $inputId, public string $packId, array $assetMap)
    {
        Validator::make(['workspace' => $workspaceId, 'task' => $taskId, 'input' => $inputId, 'pack' => $packId, 'map' => $assetMap, 'source_ids' => array_keys($assetMap)], [
            'workspace' => ['required', 'string', 'max:128'], 'task' => ['required', 'uuid'], 'input' => ['required', 'uuid'], 'pack' => ['required', 'uuid'],
            'map' => ['required', 'array', 'max:1000'], 'map.*' => ['required', 'uuid', 'distinct:strict'], 'source_ids.*' => ['required', 'uuid'],
        ])->validate();
        if (in_array($inputId, $assetMap, true)) {
            throw ValidationException::withMessages(['map' => 'Archive input cannot also be a destination audio upload.']);
        }
        ksort($assetMap, SORT_STRING);
        $this->assetMap = $assetMap;
    }
}
