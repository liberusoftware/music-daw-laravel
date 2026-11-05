<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibrary\Commands;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final readonly class RestoreAsset
{
    public function __construct(public string $workspaceId, public string $assetId, public string $replacementUploadId, public string $commandKey)
    {
        Validator::make(['workspace' => $workspaceId, 'asset' => $assetId, 'replacement' => $replacementUploadId, 'key' => $commandKey], [
            'workspace' => ['required', 'string', 'max:128'], 'asset' => ['required', 'uuid'],
            'replacement' => ['required', 'uuid'], 'key' => ['required', 'string', 'max:128'],
        ])->validate();
        if (strcasecmp($assetId, $replacementUploadId) === 0) {
            throw ValidationException::withMessages(['replacement' => 'Restore from a separate replacement upload.']);
        }
    }
}
