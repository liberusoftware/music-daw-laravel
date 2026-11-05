<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibrary\Support;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Database\ConnectionInterface;
use Liberu\Foundation\Files\Contracts\MediaAccess;
use Liberu\Music\MediaLibrary\Contracts\MediaAuthorizer;

/** Foundation media authorization for this module's assets only. */
final readonly class ScopedMediaAccess implements MediaAccess
{
    public function __construct(private ConnectionInterface $database, private ?UserProvider $users, private MediaAuthorizer $access) {}

    public function authorized(string|int|null $actorId, string $mediaId, string $operation): bool
    {
        if ($this->users === null || $actorId === null || $operation !== 'read') {
            return false;
        }
        $actor = $this->users->retrieveById($actorId);
        if ($actor === null) {
            return false;
        }
        $row = $this->database->table('music_media_uploads')->where('id', $mediaId)->where('status', 'ready')->first(['workspace_id']);
        if ($row === null) {
            return false;
        }
        try {
            $this->access->authorize($actor, $row->workspace_id, $mediaId, 'read');
        } catch (AuthorizationException) {
            return false;
        }

        return true;
    }
}
