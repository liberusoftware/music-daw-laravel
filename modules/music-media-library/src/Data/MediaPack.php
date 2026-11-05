<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibrary\Data;

use Illuminate\Support\Facades\Validator;
use JsonSerializable;
use Liberu\Music\MediaLibrary\Commands\SaveMediaPack;

final readonly class MediaPack implements JsonSerializable
{
    public function __construct(public string $workspaceId, public string $packId, public int $version, public string $name, public array $rights, public array $assets) {}

    public static function fromArray(array $document): self
    {
        Validator::make(['document' => $document], [
            'document' => ['required', 'array:schema_version,workspace_id,pack_id,version,name,rights,assets'],
            'document.schema_version' => ['required', 'integer:strict', 'in:1'],
            'document.version' => ['required', 'integer:strict', 'min:1', 'max:'.PHP_INT_MAX],
            'document.assets' => ['required', 'array', 'list', 'max:1000'],
            'document.assets.*' => ['required', 'array:reference,name,provenance'],
            'document.assets.*.name' => ['required', 'string', 'max:255'],
            'document.assets.*.provenance' => ['present', 'array:source,license,attribution'],
            'document.assets.*.provenance.*' => ['string', 'max:2048'],
            'document.assets.*.reference' => ['required', 'array:id,sha256,sample_rate,channels,frames'],
            'document.assets.*.reference.id' => ['required', 'uuid', 'distinct:strict'],
            'document.assets.*.reference.sha256' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'],
            'document.assets.*.reference.sample_rate' => ['required', 'integer:strict', 'min:1'],
            'document.assets.*.reference.channels' => ['required', 'integer:strict', 'min:1'],
            'document.assets.*.reference.frames' => ['required', 'integer:strict', 'min:1'],
            'document.workspace_id' => ['required', 'string', 'max:128'], 'document.pack_id' => ['required', 'uuid'],
            'document.name' => ['required', 'string', 'max:255'], 'document.rights' => ['required', 'array'],
        ])->validate();
        $command = new SaveMediaPack($document['workspace_id'], $document['pack_id'], $document['name'], array_column(array_column($document['assets'], 'reference'), 'id'), $document['rights'], $document['version'] - 1, 'decode');

        $assets = array_map(function (array $asset): array {
            $reference = $asset['reference'];
            ksort($asset['provenance'], SORT_STRING);

            return ['reference' => ['id' => $reference['id'], 'sha256' => $reference['sha256'], 'sample_rate' => $reference['sample_rate'],
                'channels' => $reference['channels'], 'frames' => $reference['frames']], 'name' => $asset['name'], 'provenance' => $asset['provenance']];
        }, $document['assets']);
        usort($assets, fn (array $left, array $right): int => strcmp($left['reference']['id'], $right['reference']['id']));

        return new self($command->workspaceId, $command->packId, $document['version'], $command->name, $command->rights, $assets);
    }

    public function jsonSerialize(): array
    {
        return ['schema_version' => 1, 'workspace_id' => $this->workspaceId, 'pack_id' => $this->packId,
            'version' => $this->version, 'name' => $this->name, 'rights' => $this->rights, 'assets' => $this->assets];
    }
}
