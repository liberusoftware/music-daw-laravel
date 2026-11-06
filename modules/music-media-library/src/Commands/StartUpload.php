<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibrary\Commands;

use Illuminate\Support\Facades\Validator;
use JsonSerializable;
use Liberu\Music\MediaLibrary\Support\ArchiveInputLimit;

final readonly class StartUpload implements JsonSerializable
{
    public function __construct(
        public string $workspaceId,
        public string $uploadId,
        public string $name,
        public int $bytes,
        public string $sha256,
        public array $provenance = [],
        public string $purpose = 'audio',
    ) {
        Validator::make([
            'workspace' => $workspaceId, 'id' => $uploadId, 'name' => $name,
            'bytes' => $bytes, 'sha256' => $sha256, 'provenance' => $provenance, 'purpose' => $purpose,
        ], [
            'workspace' => ['required', 'string', 'max:128'], 'id' => ['required', 'uuid'],
            'name' => ['required', 'string', 'max:255'], 'bytes' => ['required', 'integer:strict', 'between:44,'.($purpose === 'archive' ? ArchiveInputLimit::bytes() : 1073741824)],
            'purpose' => ['required', 'in:audio,archive'],
            'sha256' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'provenance' => ['array:source,license,attribution'],
            'provenance.*' => ['string', 'max:2048'],
        ])->validate();
    }

    /** Keep existing audio command fingerprints byte-for-byte compatible. */
    public function jsonSerialize(): array
    {
        $command = ['workspaceId' => $this->workspaceId, 'uploadId' => $this->uploadId, 'name' => $this->name,
            'bytes' => $this->bytes, 'sha256' => $this->sha256, 'provenance' => $this->provenance];

        return $this->purpose === 'audio' ? $command : $command + ['purpose' => $this->purpose];
    }
}
