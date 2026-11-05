<?php

declare(strict_types=1);

namespace Liberu\Music\Projects\Contracts;

/** A media owner supplies verified, immutable metadata, never client assertions. */
interface VerifiedMedia
{
    /** @return array{id: string, sha256: string, sample_rate: int, channels: int, frames: int} */
    public function resolve(string $workspaceId, string $mediaId): array;
}
