<?php

declare(strict_types=1);

namespace Liberu\Music\AudioEngine\Data;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use JsonSerializable;
use Liberu\Music\Projects\Data\SessionDocument;

/** Control-plane metadata only; never constructed or interpreted inside an audio callback. */
final readonly class GraphDefinition implements JsonSerializable
{
    private function __construct(private array $graph, private array $plan) {}

    public static function fromArray(array $input): self
    {
        $graph = Validator::make(['graph' => $input], [
            'graph' => ['required', 'array:schema_version,buffer_frames,nodes,edges'],
            'graph.schema_version' => ['required', 'integer:strict', 'in:1'],
            'graph.buffer_frames' => ['required', 'integer:strict', 'between:16,8192'],
            'graph.nodes' => ['required', 'array', 'list', 'min:1', 'max:256'],
            'graph.nodes.*' => ['required', 'array:id,kind,channels,latency_frames,media_id,gain_linear'],
            'graph.nodes.*.id' => ['required', 'uuid', 'distinct:strict'],
            'graph.nodes.*.kind' => ['required', 'in:silence,media,gain,mixer,output'],
            'graph.nodes.*.channels' => ['required', 'integer:strict', 'between:1,64'],
            'graph.nodes.*.latency_frames' => ['required', 'integer:strict', 'between:0,1048576'],
            'graph.nodes.*.media_id' => ['sometimes', 'string', 'max:128'],
            'graph.nodes.*.gain_linear' => ['sometimes'],
            'graph.edges' => ['present', 'array', 'list', 'max:1024'],
            'graph.edges.*' => ['required', 'array:from,to'],
            'graph.edges.*.from' => ['required', 'uuid'], 'graph.edges.*.to' => ['required', 'uuid'],
        ])->validate()['graph'];
        if (($graph['buffer_frames'] & ($graph['buffer_frames'] - 1)) !== 0) {
            throw ValidationException::withMessages(['graph.buffer_frames' => 'Buffer frames must be a power of two.']);
        }
        $nodes = array_column($graph['nodes'], null, 'id');
        $incoming = $outgoing = $arrival = $latency = [];
        $outputs = [];
        foreach ($nodes as $id => $node) {
            $incoming[$id] = $outgoing[$id] = [];
            $arrival[$id] = 0;
            if (($node['kind'] === 'media') !== array_key_exists('media_id', $node)) {
                throw ValidationException::withMessages(['graph.nodes' => 'Only media nodes require a media identifier.']);
            }
            if ($node['kind'] === 'gain') {
                $gain = $node['gain_linear'] ?? null;
                if ((! is_int($gain) && ! is_float($gain)) || ! is_finite((float) $gain) || $gain < 0 || $gain > 16) {
                    throw ValidationException::withMessages(['graph.nodes' => 'Gain must be a finite linear amplitude between zero and sixteen.']);
                }
            } elseif (array_key_exists('gain_linear', $node)) {
                throw ValidationException::withMessages(['graph.nodes' => 'Only gain nodes accept a linear gain.']);
            }
            if ($node['kind'] === 'output') {
                $outputs[] = $id;
            }
        }
        foreach ($graph['edges'] as $edge) {
            if (! isset($nodes[$edge['from']], $nodes[$edge['to']]) || $edge['from'] === $edge['to']
                || isset($outgoing[$edge['from']][$edge['to']]) || $nodes[$edge['from']]['channels'] !== $nodes[$edge['to']]['channels']) {
                throw ValidationException::withMessages(['graph.edges' => 'Edges must connect distinct existing nodes with equal channel counts and cannot be duplicated.']);
            }
            $outgoing[$edge['from']][$edge['to']] = true;
            $incoming[$edge['to']][$edge['from']] = true;
        }
        if ($outputs === []) {
            throw ValidationException::withMessages(['graph.nodes' => 'At least one output is required.']);
        }
        foreach ($nodes as $id => $node) {
            $count = count($incoming[$id]);
            if ((in_array($node['kind'], ['silence', 'media'], true) && $count !== 0)
                || (in_array($node['kind'], ['gain', 'output'], true) && $count !== 1)
                || ($node['kind'] === 'mixer' && $count < 1) || ($node['kind'] === 'output' && $outgoing[$id] !== [])) {
                throw ValidationException::withMessages(['graph.nodes' => 'Source, processor, mixer and output connections do not match their declared ports.']);
            }
        }
        $degree = array_map('count', $incoming);
        $order = array_keys(array_filter($degree, fn ($count) => $count === 0));
        for ($cursor = 0; isset($order[$cursor]); $cursor++) {
            $id = $order[$cursor];
            $latency[$id] = $arrival[$id] + $nodes[$id]['latency_frames'];
            foreach ($outgoing[$id] as $target => $_) {
                $arrival[$target] = max($arrival[$target], $latency[$id]);
                if (--$degree[$target] === 0) {
                    $order[] = $target;
                }
            }
        }
        if (count($order) !== count($nodes)) {
            throw ValidationException::withMessages(['graph.edges' => 'This schema requires an acyclic graph.']);
        }
        $reachable = array_fill_keys($outputs, true);
        $queue = $outputs;
        for ($cursor = 0; isset($queue[$cursor]); $cursor++) {
            foreach ($incoming[$queue[$cursor]] as $source => $_) {
                if (! isset($reachable[$source])) {
                    $reachable[$source] = true;
                    $queue[] = $source;
                }
            }
        }
        if (count($reachable) !== count($nodes)) {
            throw ValidationException::withMessages(['graph.nodes' => 'Every node must contribute to an output.']);
        }
        $delays = array_map(fn ($edge) => $edge + ['delay_frames' => $arrival[$edge['to']] - $latency[$edge['from']]], $graph['edges']);
        if (strlen(json_encode($graph, JSON_THROW_ON_ERROR)) > 262144) {
            throw ValidationException::withMessages(['graph' => 'Graph metadata may not exceed 256 KiB.']);
        }

        return new self($graph, ['node_order' => $order, 'node_latency_frames' => $latency, 'edge_delays' => $delays]);
    }

    /** Public immutable references only; transport frame counts use decimal strings. */
    public function mediaFor(SessionDocument $document): array
    {
        $assets = array_column($document->assets(), null, 'id');
        $used = [];
        foreach ($this->graph['nodes'] as $node) {
            if ($node['kind'] !== 'media') {
                continue;
            }
            $asset = $assets[$node['media_id']] ?? null;
            if ($asset === null || $asset['channels'] !== $node['channels'] || $asset['sample_rate'] !== $document->jsonSerialize()['sample_rate']) {
                throw ValidationException::withMessages(['graph.nodes' => 'Media must resolve in the saved session with matching channels and sample rate. Resampling requires an explicitly supported runtime schema.']);
            }
            $asset['frames'] = (string) $asset['frames'];
            $used[$asset['id']] = $asset;
        }

        return array_values($used);
    }

    public function latencyPlan(): array
    {
        return $this->plan;
    }

    public function jsonSerialize(): array
    {
        return $this->graph;
    }
}
