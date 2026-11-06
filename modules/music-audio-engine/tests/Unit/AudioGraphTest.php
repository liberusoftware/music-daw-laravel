<?php

use Illuminate\Validation\ValidationException;
use Liberu\Music\AudioEngine\Commands\SaveGraph;
use Liberu\Music\AudioEngine\Data\GraphDefinition;
use Liberu\Music\AudioEngine\Data\GraphSnapshot;
use Liberu\Music\Projects\Data\SessionDocument;
use Liberu\Music\Projects\Data\SessionRevision;

function engineNode(int $number, string $kind, int $latency = 0): array
{
    return ['id' => sprintf('00000000-0000-4000-8000-%012d', $number), 'kind' => $kind, 'channels' => 2, 'latency_frames' => $latency];
}

function engineEdge(int $from, int $to): array
{
    return ['from' => engineNode($from, 'silence')['id'], 'to' => engineNode($to, 'output')['id']];
}

function engineGraph(): array
{
    return ['schema_version' => 1, 'buffer_frames' => 128, 'nodes' => [
        engineNode(1, 'silence'), engineNode(2, 'gain', 64) + ['gain_linear' => 1],
        engineNode(3, 'silence'), engineNode(4, 'gain', 16) + ['gain_linear' => 0.5],
        engineNode(5, 'mixer', 8), engineNode(6, 'output'),
    ], 'edges' => [engineEdge(1, 2), engineEdge(3, 4), engineEdge(2, 5), engineEdge(4, 5), engineEdge(5, 6)]];
}

it('plans fan-in compensation in sample frames without changing the input graph', function () {
    $input = engineGraph();
    $graph = GraphDefinition::fromArray($input);
    $plan = $graph->latencyPlan();
    expect($graph->jsonSerialize())->toBe($input)
        ->and($plan['node_order'])->toBe(array_map(fn ($id) => engineNode($id, 'silence')['id'], [1, 3, 2, 4, 5, 6]))
        ->and(array_values($plan['node_latency_frames']))->toBe([0, 0, 64, 16, 72, 72])
        ->and(array_column($plan['edge_delays'], 'delay_frames'))->toBe([0, 0, 0, 48, 0])
        ->and($graph->mediaFor(SessionDocument::empty()))->toBe([]);
    $input['nodes'][0]['channels'] = 1;
    expect($graph->jsonSerialize()['nodes'][0]['channels'])->toBe(2);
});

it('rejects malformed graph metadata', function (string $path, mixed $value) {
    $input = engineGraph();
    data_set($input, $path, $value);
    expect(fn () => GraphDefinition::fromArray($input))->toThrow(ValidationException::class);
})->with([
    'schema' => ['schema_version', 2], 'strict schema' => ['schema_version', '1'],
    'strict buffer' => ['buffer_frames', '128'], 'buffer range' => ['buffer_frames', 8],
    'buffer power' => ['buffer_frames', 129], 'unknown field' => ['driver', 'php'],
    'node uuid' => ['nodes.0.id', 'bad'], 'duplicate uuid' => ['nodes.0.id', engineNode(2, 'gain')['id']],
    'channel count' => ['nodes.0.channels', 65], 'latency units' => ['nodes.0.latency_frames', 0.5],
    'latency range' => ['nodes.0.latency_frames', -1], 'unknown processor' => ['nodes.1.kind', 'plugin'],
    'no nodes' => ['nodes', []], 'non-list nodes' => ['nodes', ['source' => engineNode(1, 'silence')]],
    'gain text' => ['nodes.1.gain_linear', '1'], 'gain negative' => ['nodes.1.gain_linear', -0.1],
    'gain excessive' => ['nodes.1.gain_linear', 17], 'gain infinite' => ['nodes.1.gain_linear', INF],
    'gain NaN' => ['nodes.1.gain_linear', NAN], 'gain null' => ['nodes.1.gain_linear', null],
    'gain on source' => ['nodes.0.gain_linear', 1], 'media on source' => ['nodes.0.media_id', 'asset'],
    'missing media id' => ['nodes.0.kind', 'media'], 'edge unknown node' => ['edges.0.from', engineNode(99, 'silence')['id']],
    'edge self' => ['edges.0.to', engineNode(1, 'silence')['id']],
    'channels differ' => ['nodes.0.channels', 1], 'no processor input' => ['edges.0.to', engineNode(5, 'mixer')['id']],
    'extra edge key' => ['edges.0.private', true],
]);

it('rejects duplicate edges, invalid ports, cycles and dead branches', function () {
    $input = engineGraph();
    $input['edges'][] = $input['edges'][0];
    expect(fn () => GraphDefinition::fromArray($input))->toThrow(ValidationException::class, 'duplicated');
    $input = engineGraph();
    $input['nodes'][5]['kind'] = 'mixer';
    expect(fn () => GraphDefinition::fromArray($input))->toThrow(ValidationException::class, 'output');
    foreach ([engineEdge(2, 1), engineEdge(1, 4), engineEdge(6, 5)] as $edge) {
        $input = engineGraph();
        $input['edges'][] = $edge;
        expect(fn () => GraphDefinition::fromArray($input))->toThrow(ValidationException::class, 'ports');
    }
    $input = engineGraph();
    $input['nodes'][] = engineNode(7, 'mixer');
    expect(fn () => GraphDefinition::fromArray($input))->toThrow(ValidationException::class, 'ports');
    $input['edges'][] = engineEdge(5, 7);
    $input['edges'][] = engineEdge(7, 5);
    expect(fn () => GraphDefinition::fromArray($input))->toThrow(ValidationException::class, 'acyclic');
    $input = engineGraph();
    $input['nodes'][] = engineNode(7, 'silence');
    expect(fn () => GraphDefinition::fromArray($input))->toThrow(ValidationException::class, 'contribute');
    $input = engineGraph();
    unset($input['nodes'][1]['gain_linear']);
    expect(fn () => GraphDefinition::fromArray($input))->toThrow(ValidationException::class, 'Gain');
});

it('bounds the serialized control-plane envelope even when identifiers need JSON escaping', function () {
    $input = ['schema_version' => 1, 'buffer_frames' => 128, 'nodes' => [], 'edges' => []];
    for ($index = 1; $index <= 254; $index++) {
        $input['nodes'][] = engineNode($index, 'media') + ['media_id' => str_repeat('🎵', 128)];
        $input['edges'][] = engineEdge($index, 255);
    }
    $input['nodes'][] = engineNode(255, 'mixer');
    $input['nodes'][] = engineNode(256, 'output');
    $input['edges'][] = engineEdge(255, 256);
    expect(fn () => GraphDefinition::fromArray($input))->toThrow(ValidationException::class, '256 KiB');
});

it('resolves deduplicated public media with lossless wire frame counts', function () {
    $input = engineGraph();
    foreach ([0, 2] as $index) {
        $input['nodes'][$index]['kind'] = 'media';
        $input['nodes'][$index]['media_id'] = 'asset-1';
    }
    $document = SessionDocument::empty()->jsonSerialize();
    $asset = ['id' => 'asset-1', 'sha256' => str_repeat('a', 64), 'sample_rate' => 48000, 'channels' => 2, 'frames' => PHP_INT_MAX];
    $document['assets'] = [$asset];
    $graph = GraphDefinition::fromArray($input);
    expect($graph->mediaFor(SessionDocument::fromArray($document)))->toBe([array_replace($asset, ['frames' => (string) PHP_INT_MAX])]);
    foreach ([[], [array_replace($asset, ['channels' => 1])], [array_replace($asset, ['sample_rate' => 96000])]] as $assets) {
        $document['assets'] = $assets;
        expect(fn () => $graph->mediaFor(SessionDocument::fromArray($document)))->toThrow(ValidationException::class, 'matching');
    }
});

it('pins transport metadata to a saved revision and excludes unrelated private document state', function () {
    $document = SessionDocument::empty()->jsonSerialize();
    $document['modules'] = ['private' => ['schema_version' => 99, 'state' => ['secret' => 'never-export']],
        'music.audio-engine' => ['schema_version' => 1, 'state' => engineGraph()]];
    $revision = new SessionRevision('studio', engineNode(99, 'silence')['id'], 'Private title', PHP_INT_MAX, SessionDocument::fromArray($document), false, false);
    $snapshot = GraphSnapshot::fromRevision($revision);
    $payload = $snapshot->jsonSerialize();
    expect($payload['project_version'])->toBe((string) PHP_INT_MAX)
        ->and($payload['project_checksum'])->toBe($revision->document->checksum())
        ->and($payload['runtime_required'])->toBeTrue()
        ->and($payload['units'])->toBe(['positions' => 'sample_frames', 'latencies' => 'sample_frames', 'gain' => 'linear_amplitude'])
        ->and(json_encode($payload))->not->toContain('never-export', 'Private title');
    unset($payload['snapshot_checksum']);
    expect($snapshot->checksum())->toBe(hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)));
    foreach ([[], ['music.audio-engine' => ['schema_version' => 2, 'state' => engineGraph()]]] as $modules) {
        $document['modules'] = $modules;
        $unsupported = new SessionRevision('studio', $revision->projectId, 'Title', 1, SessionDocument::fromArray($document), false, false);
        expect(fn () => GraphSnapshot::fromRevision($unsupported))->toThrow(ValidationException::class, 'supported');
    }
});

it('validates scoped graph save commands', function () {
    $graph = GraphDefinition::fromArray(engineGraph());
    $command = new SaveGraph('studio', engineNode(99, 'silence')['id'], $graph, 1, 'checkpoint');
    expect($command->graph)->toBe($graph)->and($command->expectedVersion)->toBe(1);
    foreach ([['', $command->projectId, 1, 'key'], ['studio', 'bad', 1, 'key'], ['studio', $command->projectId, 0, 'key'], ['studio', $command->projectId, 1, ''], ['studio', $command->projectId, 1, str_repeat('x', 97)]] as [$workspace, $project, $version, $key]) {
        expect(fn () => new SaveGraph($workspace, $project, $graph, $version, $key))->toThrow(ValidationException::class);
    }
});
