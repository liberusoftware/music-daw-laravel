<?php

declare(strict_types=1);

namespace Liberu\Music\Projects\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Liberu\Foundation\Files\Contracts\MediaAccess;
use Liberu\Music\Projects\Commands\SaveSession;
use Liberu\Music\Projects\Contracts\ProjectAccess;
use Liberu\Music\Projects\Contracts\VerifiedMedia;
use Liberu\Music\Projects\Data\SessionDocument;
use Liberu\Music\Projects\Data\SessionPage;
use Liberu\Music\Projects\Data\SessionRevision;
use Liberu\Music\Projects\Events\SessionSaved;
use Liberu\Music\Projects\Exceptions\RevisionConflict;

final readonly class ProjectSessions
{
    public function __construct(
        private ConnectionInterface $database,
        private ProjectAccess $access,
        private VerifiedMedia $media,
        private Dispatcher $events,
        private ?MediaAccess $mediaAccess = null,
    ) {}

    public function save(Authenticatable $actor, SaveSession $command): SessionRevision
    {
        $this->access->authorize($actor, $command->workspaceId, $command->projectId, 'write');
        $this->verifyMedia($actor, $command->workspaceId, $command->document);
        $fingerprint = hash('sha256', json_encode([
            (string) $actor->getAuthIdentifier(), $command->workspaceId, $command->projectId,
            $command->title, $command->document->checksum(), $command->expectedVersion,
            $command->autosave, $command->template,
        ], JSON_THROW_ON_ERROR));

        return $this->database->transaction(function () use ($actor, $command, $fingerprint): SessionRevision {
            if ($command->expectedVersion === 0) {
                // The unique project key arbitrates simultaneous creates. Lock the same
                // row for all later writes, including autosaves and command replay.
                $this->database->table('music_projects')->insertOrIgnore([
                    'id' => $command->projectId, 'workspace_id' => $command->workspaceId,
                    'version' => 0, 'template' => $command->template,
                ]);
            }
            $project = $this->database->table('music_projects')
                ->where('workspace_id', $command->workspaceId)->where('id', $command->projectId)
                ->lockForUpdate()->firstOrFail();
            $replay = $this->revisions($command->workspaceId, $command->projectId)
                ->where('idempotency_key', $command->idempotencyKey)->first();
            if ($replay !== null) {
                if (! hash_equals($replay->fingerprint, $fingerprint)) {
                    throw new RevisionConflict('The idempotency key was already used for a different command.');
                }

                return $this->revision($replay);
            }
            if ((int) $project->version !== $command->expectedVersion || (bool) $project->template !== $command->template) {
                throw new RevisionConflict('The session changed; reload it before saving.');
            }
            $version = $command->expectedVersion + 1;
            $updated = $this->database->table('music_projects')->where('id', $command->projectId)
                ->where('version', $command->expectedVersion)->update(['version' => $version]);
            if ($updated !== 1) {
                throw new RevisionConflict('The session changed during this save.');
            }
            $this->database->table('music_project_revisions')->insert([
                'project_id' => $command->projectId, 'workspace_id' => $command->workspaceId,
                'title' => $command->title, 'version' => $version,
                'document' => json_encode($command->document, JSON_THROW_ON_ERROR),
                'checksum' => $command->document->checksum(), 'autosave' => $command->autosave,
                'template' => $command->template, 'actor_id' => (string) $actor->getAuthIdentifier(),
                'idempotency_key' => $command->idempotencyKey, 'fingerprint' => $fingerprint,
                'created_at' => now(),
            ]);
            $revision = new SessionRevision($command->workspaceId, $command->projectId, $command->title, $version, $command->document, $command->autosave, $command->template);
            $this->events->dispatch(new SessionSaved($revision, (string) $actor->getAuthIdentifier()));

            return $revision;
        }, 3);
    }

    public function get(Authenticatable $actor, string $workspaceId, string $projectId, ?int $version = null): SessionRevision
    {
        $this->access->authorize($actor, $workspaceId, $projectId, 'read');
        $query = $this->revisions($workspaceId, $projectId);
        if ($version !== null) {
            $query->where('version', $version);
        }
        $revision = $this->revision($query->orderByDesc('version')->firstOrFail());
        $this->verifyMedia($actor, $workspaceId, $revision->document);

        return $revision;
    }

    /** @return list<SessionRevision> */
    public function history(Authenticatable $actor, string $workspaceId, string $projectId, int $limit = 25, ?int $before = null): array
    {
        $this->access->authorize($actor, $workspaceId, $projectId, 'read');
        Validator::make(['limit' => $limit, 'before' => $before], [
            'limit' => ['integer:strict', 'between:1,100'], 'before' => ['nullable', 'integer:strict', 'min:1'],
        ])->validate();
        $query = $this->revisions($workspaceId, $projectId);
        if ($before !== null) {
            $query->where('version', '<', $before);
        }

        return $query->orderByDesc('version')->limit($limit)->get()->map(function ($row) use ($actor, $workspaceId): SessionRevision {
            $revision = $this->revision($row);
            $this->verifyMedia($actor, $workspaceId, $revision->document);

            return $revision;
        })->all();
    }

    public function browse(Authenticatable $actor, string $workspaceId, int $limit = 25, ?int $before = null, ?bool $templates = null): SessionPage
    {
        $this->access->authorize($actor, $workspaceId, '*', 'list');
        Validator::make(['limit' => $limit, 'before' => $before], [
            'limit' => ['integer:strict', 'between:1,100'], 'before' => ['nullable', 'integer:strict', 'min:1'],
        ])->validate();
        $query = $this->database->table('music_project_revisions as revisions')
            ->join('music_projects as projects', function ($join): void {
                $join->on('projects.id', '=', 'revisions.project_id')->on('projects.version', '=', 'revisions.version');
            })
            ->where('revisions.workspace_id', $workspaceId)->where('projects.workspace_id', $workspaceId)
            ->select('revisions.*');
        if ($before !== null) {
            $query->where('revisions.id', '<', $before);
        }
        if ($templates !== null) {
            $query->where('projects.template', $templates);
        }
        $rows = $query->orderByDesc('revisions.id')->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;
        $rows = $rows->take($limit);
        $items = $rows->map(function ($row) use ($actor, $workspaceId): SessionRevision {
            $this->access->authorize($actor, $workspaceId, $row->project_id, 'read');
            $revision = $this->revision($row);
            $this->verifyMedia($actor, $workspaceId, $revision->document);

            return $revision;
        })->all();

        return new SessionPage($items, $hasMore ? (int) $rows->last()->id : null);
    }

    public function recover(Authenticatable $actor, string $workspaceId, string $projectId, int $sourceVersion, int $expectedVersion, string $idempotencyKey): SessionRevision
    {
        $source = $this->get($actor, $workspaceId, $projectId, $sourceVersion);

        return $this->save($actor, new SaveSession($workspaceId, $projectId, $source->title, $source->document, $expectedVersion, $idempotencyKey, template: $source->template));
    }

    public function export(Authenticatable $actor, string $workspaceId, string $projectId, ?int $version = null): string
    {
        $revision = $this->get($actor, $workspaceId, $projectId, $version);

        return json_encode(['format' => 'liberu.music.session', 'format_version' => 1, 'title' => $revision->title,
            'document' => $revision->document, 'checksum' => $revision->document->checksum()], JSON_THROW_ON_ERROR);
    }

    public function import(Authenticatable $actor, string $workspaceId, string $projectId, string $manifest, string $idempotencyKey): SessionRevision
    {
        $this->access->authorize($actor, $workspaceId, $projectId, 'write');
        if (strlen($manifest) > 17 * 1024 * 1024) {
            throw ValidationException::withMessages(['manifest' => 'Portable manifests may not exceed 17 MiB.']);
        }
        $payload = json_decode($manifest, true, 512, JSON_THROW_ON_ERROR);
        Validator::make(is_array($payload) ? $payload : [], [
            'format' => ['required', 'in:liberu.music.session'], 'format_version' => ['required', 'integer:strict', 'in:1'],
            'title' => ['required', 'string', 'max:255'], 'document' => ['required', 'array'],
            'checksum' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
        ])->validate();
        $document = SessionDocument::fromArray($payload['document']);
        if (! hash_equals($document->checksum(), $payload['checksum'])) {
            throw ValidationException::withMessages(['checksum' => 'The session manifest checksum does not match.']);
        }

        return $this->save($actor, new SaveSession($workspaceId, $projectId, $payload['title'], $document, 0, $idempotencyKey));
    }

    private function revisions(string $workspaceId, string $projectId): Builder
    {
        return $this->database->table('music_project_revisions')->where('workspace_id', $workspaceId)->where('project_id', $projectId);
    }

    private function revision(object $row): SessionRevision
    {
        $document = SessionDocument::fromArray(json_decode($row->document, true, flags: JSON_THROW_ON_ERROR));
        if (! hash_equals($row->checksum, $document->checksum())) {
            throw ValidationException::withMessages(['checksum' => 'The stored revision failed its integrity check.']);
        }

        return new SessionRevision($row->workspace_id, $row->project_id, $row->title, (int) $row->version, $document, (bool) $row->autosave, (bool) $row->template);
    }

    private function verifyMedia(Authenticatable $actor, string $workspaceId, SessionDocument $document): void
    {
        foreach ($document->assets() as $asset) {
            if ($this->mediaAccess === null || ! $this->mediaAccess->authorized($actor->getAuthIdentifier(), $asset['id'], 'read')) {
                throw new AuthorizationException('The referenced media is unavailable to this actor.');
            }
            $verified = $this->media->resolve($workspaceId, $asset['id']);
            foreach ($asset as $key => $value) {
                if (! array_key_exists($key, $verified) || $verified[$key] !== $value) {
                    throw ValidationException::withMessages(['assets' => 'The referenced media has not been verified or its metadata differs.']);
                }
            }
        }
    }
}
