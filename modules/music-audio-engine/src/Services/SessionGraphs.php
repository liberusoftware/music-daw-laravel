<?php

declare(strict_types=1);

namespace Liberu\Music\AudioEngine\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Liberu\Music\AudioEngine\Commands\SaveGraph;
use Liberu\Music\AudioEngine\Data\GraphSnapshot;
use Liberu\Music\Projects\Commands\SaveSession;
use Liberu\Music\Projects\Data\SessionDocument;
use Liberu\Music\Projects\Services\ProjectSessions;

final readonly class SessionGraphs
{
    public function __construct(private ProjectSessions $projects) {}

    public function snapshot(Authenticatable $actor, string $workspace, string $project, ?int $version = null): GraphSnapshot
    {
        return GraphSnapshot::fromRevision($this->projects->get($actor, $workspace, $project, $version));
    }

    public function save(Authenticatable $actor, SaveGraph $command): GraphSnapshot
    {
        $base = $this->projects->get($actor, $command->workspaceId, $command->projectId, $command->expectedVersion);
        $document = $base->document->jsonSerialize();
        if (isset($document['modules']['music.audio-engine'])) {
            GraphSnapshot::fromRevision($base);
        }
        $command->graph->mediaFor($base->document);
        $document['modules']['music.audio-engine'] = ['schema_version' => 1, 'state' => $command->graph->jsonSerialize()];
        $saved = $this->projects->save($actor, new SaveSession($command->workspaceId, $command->projectId, $base->title,
            SessionDocument::fromArray($document), $command->expectedVersion, 'audio-engine:graph:'.$command->commandKey, template: $base->template));

        return GraphSnapshot::fromRevision($saved);
    }
}
