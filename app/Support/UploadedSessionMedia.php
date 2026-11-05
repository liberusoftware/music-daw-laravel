<?php

namespace App\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Liberu\Music\MediaLibrary\Services\MediaUploads;
use Liberu\Music\Projects\Contracts\VerifiedMedia;

final readonly class UploadedSessionMedia implements VerifiedMedia
{
    public function __construct(private MediaUploads $uploads) {}

    public function resolve(Authenticatable $actor, string $workspaceId, string $mediaId): array
    {
        return $this->uploads->get($actor, $workspaceId, $mediaId)->reference();
    }
}
