<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibrary\Services;

use Illuminate\Database\ConnectionInterface;
use LogicException;

/** Serializes publication and physical deletion of one workspace content address. */
final readonly class ContentLocks
{
    public function __construct(private ConnectionInterface $database) {}

    public function acquire(string $workspaceId, string $checksum, ConnectionInterface $transaction): void
    {
        if ($transaction !== $this->database || $transaction->transactionLevel() < 1) {
            throw new LogicException('Content locking must share the active media transaction.');
        }
        $id = hash('sha256', $workspaceId."\0".$checksum);
        $this->database->table('music_media_content_locks')->insertOrIgnore(['id' => $id]);
        $this->database->table('music_media_content_locks')->where('id', $id)->lockForUpdate()->firstOrFail();
    }
}
