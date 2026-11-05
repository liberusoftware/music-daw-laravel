<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibraryApi\Support;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use JsonException;

final class CatalogCursor
{
    public function encode(string $actorId, string $workspaceId, array $filters, string $beforeAssetId): string
    {
        return Crypt::encryptString(json_encode(['format' => 'music.media.catalog.v1', 'scope' => $this->scope($actorId, $workspaceId, $filters),
            'before' => $beforeAssetId, 'expires' => now()->addMinutes(15)->timestamp], JSON_THROW_ON_ERROR));
    }

    public function decode(?string $cursor, string $actorId, string $workspaceId, array $filters): ?string
    {
        if ($cursor === null) {
            return null;
        }
        try {
            $data = json_decode(Crypt::decryptString($cursor), true, 8, JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            throw $this->invalid();
        }
        if (! is_array($data) || ($data['format'] ?? null) !== 'music.media.catalog.v1' || ($data['scope'] ?? null) !== $this->scope($actorId, $workspaceId, $filters)
            || ! is_string($data['before'] ?? null) || ! Str::isUuid($data['before']) || ! is_int($data['expires'] ?? null) || $data['expires'] <= now()->timestamp) {
            throw $this->invalid();
        }

        return $data['before'];
    }

    private function scope(string $actorId, string $workspaceId, array $filters): string
    {
        return hash('sha256', json_encode([$actorId, $workspaceId, $filters], JSON_THROW_ON_ERROR));
    }

    private function invalid(): ValidationException
    {
        return ValidationException::withMessages(['page.after' => 'The catalog cursor is invalid or expired. Restart this authorized search.']);
    }
}
