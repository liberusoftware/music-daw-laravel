<?php

namespace App\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Liberu\Foundation\Audit\Contracts\AuditRecorder;
use Liberu\Foundation\Audit\Support\AuditContext;
use Liberu\Music\Projects\Contracts\SessionAudit;
use Liberu\Music\Projects\Data\SessionRevision;
use LogicException;

final readonly class AuditedSessionRevisions implements SessionAudit
{
    public function __construct(private AuditRecorder $recorder) {}

    public function record(Authenticatable $actor, SessionRevision $revision, string $operation, ?int $sourceVersion, ConnectionInterface $transaction): void
    {
        // The foundation recorder uses the host default connection. Never claim
        // atomicity when a caller has moved Music persistence elsewhere.
        if ($transaction !== DB::connection() || $transaction->transactionLevel() < 1) {
            throw new LogicException('Session auditing requires the active host database transaction.');
        }
        $correlation = request()->attributes->get('correlation_id');
        $context = new AuditContext($actor->getAuthIdentifier(), $actor instanceof Model ? $actor->getMorphClass() : null,
            $revision->workspaceId, null, is_string($correlation) && Str::isUuid($correlation) ? $correlation : null);
        $document = $revision->document->jsonSerialize();
        // Foundation morph identifiers are integers; keep the domain UUID in
        // the structured summary instead of coercing it into subject_id.
        $this->recorder->record('music.session.'.$operation, 'music-session', null,
            ['project_id' => $revision->projectId, 'version' => $revision->version - 1],
            ['project_id' => $revision->projectId, 'version' => $revision->version, 'source_version' => $sourceVersion,
                'checksum' => $revision->document->checksum(), 'schema_version' => $document['schema_version'],
                'autosave' => $revision->autosave, 'template' => $revision->template,
                'track_count' => count($document['tracks']), 'asset_count' => count($document['assets'])], $context);
    }
}
