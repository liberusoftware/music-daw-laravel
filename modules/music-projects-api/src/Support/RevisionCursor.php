<?php

declare(strict_types=1);

namespace Liberu\Music\ProjectsApi\Support;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;
use JsonException;

/** A cursor identifies a stream position; it never grants access to a session. */
final class RevisionCursor
{
    public function encode(string $workspaceId, string $projectId, int $version): string
    {
        return Crypt::encryptString(json_encode(['format' => 'liberu.music.session-changes', 'schema_version' => 1,
            'workspace_id' => $workspaceId, 'project_id' => $projectId, 'version' => $version], JSON_THROW_ON_ERROR));
    }

    public function decode(?string $cursor, string $workspaceId, string $projectId): int
    {
        if ($cursor === null) {
            return 0;
        }
        try {
            $payload = json_decode(Crypt::decryptString($cursor), true, 8, JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            throw $this->invalid();
        }
        if (! is_array($payload) || ($payload['format'] ?? null) !== 'liberu.music.session-changes' || ($payload['schema_version'] ?? null) !== 1
            || ($payload['workspace_id'] ?? null) !== $workspaceId || ($payload['project_id'] ?? null) !== $projectId
            || ! is_int($payload['version'] ?? null) || $payload['version'] < 0) {
            throw $this->invalid();
        }

        return $payload['version'];
    }

    private function invalid(): ValidationException
    {
        return ValidationException::withMessages(['page.after' => 'The reconnect cursor is invalid for this session. Reload an authorized snapshot to resynchronize.']);
    }
}
