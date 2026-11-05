<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibrary\Commands;

use Illuminate\Support\Facades\Validator;

final readonly class StartUpload
{
    public function __construct(
        public string $workspaceId,
        public string $uploadId,
        public string $name,
        public int $bytes,
        public string $sha256,
        public array $provenance = [],
    ) {
        Validator::make([
            'workspace' => $workspaceId, 'id' => $uploadId, 'name' => $name,
            'bytes' => $bytes, 'sha256' => $sha256, 'provenance' => $provenance,
        ], [
            'workspace' => ['required', 'string', 'max:128'], 'id' => ['required', 'uuid'],
            'name' => ['required', 'string', 'max:255'], 'bytes' => ['required', 'integer:strict', 'between:44,1073741824'],
            'sha256' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'provenance' => ['array:source,license,attribution'],
            'provenance.*' => ['string', 'max:2048'],
        ])->validate();
    }
}
