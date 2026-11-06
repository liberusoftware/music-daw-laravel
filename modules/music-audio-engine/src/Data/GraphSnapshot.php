<?php

declare(strict_types=1);

namespace Liberu\Music\AudioEngine\Data;

use Illuminate\Validation\ValidationException;
use JsonSerializable;
use Liberu\Music\Projects\Data\SessionRevision;

final readonly class GraphSnapshot implements JsonSerializable
{
    private function __construct(private array $payload) {}

    public static function fromRevision(SessionRevision $revision): self
    {
        $document = $revision->document->jsonSerialize();
        $block = $document['modules']['music.audio-engine'] ?? null;
        if ($block === null || $block['schema_version'] !== 1) {
            throw ValidationException::withMessages(['graph' => 'The saved session has no supported engine graph. Preserve unsupported state and review migration before replacement.']);
        }
        $graph = GraphDefinition::fromArray($block['state']);

        return new self(['format' => 'liberu.music.engine-graph', 'protocol_version' => 1,
            'workspace_id' => $revision->workspaceId, 'project_id' => $revision->projectId,
            'project_version' => (string) $revision->version, 'project_checksum' => $revision->document->checksum(),
            'sample_rate' => $document['sample_rate'], 'runtime_required' => true,
            'units' => ['positions' => 'sample_frames', 'latencies' => 'sample_frames', 'gain' => 'linear_amplitude'],
            'graph' => $graph->jsonSerialize(), 'assets' => $graph->mediaFor($revision->document), 'declared_latency_plan' => $graph->latencyPlan()]);
    }

    public function checksum(): string
    {
        return hash('sha256', json_encode($this->payload, JSON_THROW_ON_ERROR));
    }

    public function jsonSerialize(): array
    {
        return $this->payload + ['snapshot_checksum' => $this->checksum()];
    }
}
