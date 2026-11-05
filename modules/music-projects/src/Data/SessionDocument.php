<?php

declare(strict_types=1);

namespace Liberu\Music\Projects\Data;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use JsonSerializable;

final readonly class SessionDocument implements JsonSerializable
{
    private function __construct(private array $document) {}

    public static function fromArray(array $document): self
    {
        Validator::make($document, [
            'schema_version' => ['required', 'integer:strict', 'in:1'],
            'engine_version' => ['required', 'string', 'max:100'],
            'sample_rate' => ['required', 'integer:strict', 'between:8000,384000'],
            'tracks' => ['present', 'array', 'list', 'max:2048'],
            'tracks.*' => ['required', 'array:id,name,kind,parent_id,media_ids'],
            'tracks.*.id' => ['required', 'uuid', 'distinct:strict'],
            'tracks.*.name' => ['required', 'string', 'max:255'],
            'tracks.*.kind' => ['required', 'in:audio,midi,folder'],
            'tracks.*.parent_id' => ['present', 'nullable', 'uuid'],
            'tracks.*.media_ids' => ['present', 'array', 'list', 'max:10000'],
            'tracks.*.media_ids.*' => ['required', 'string', 'max:128'],
            'assets' => ['present', 'array', 'list', 'max:10000'],
            'assets.*' => ['required', 'array:id,sha256,sample_rate,channels,frames'],
            'assets.*.id' => ['required', 'string', 'max:128', 'distinct:strict'],
            'assets.*.sha256' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'assets.*.sample_rate' => ['required', 'integer:strict', 'between:8000,384000'],
            'assets.*.channels' => ['required', 'integer:strict', 'between:1,64'],
            'assets.*.frames' => ['required', 'integer:strict', 'min:0'],
            'modules' => ['present', 'array'],
            'modules.*' => ['required', 'array:schema_version,state'],
            'modules.*.schema_version' => ['required', 'integer:strict', 'min:1'],
            'modules.*.state' => ['present', 'array'],
        ])->validate();

        if (array_diff(array_keys($document), ['schema_version', 'engine_version', 'sample_rate', 'tracks', 'assets', 'modules']) !== []) {
            throw ValidationException::withMessages(['document' => 'Unknown top-level document fields.']);
        }

        $tracks = array_column($document['tracks'], null, 'id');
        $assets = array_column($document['assets'], null, 'id');
        foreach ($tracks as $track) {
            foreach ($track['media_ids'] as $mediaId) {
                if (! isset($assets[$mediaId]) || $track['kind'] === 'folder') {
                    throw ValidationException::withMessages(['tracks' => 'Media references must resolve in the asset manifest and cannot belong to folders.']);
                }
            }
            $visited = [$track['id'] => true];
            $parentId = $track['parent_id'];
            while ($parentId !== null) {
                if (isset($visited[$parentId]) || ! isset($tracks[$parentId]) || $tracks[$parentId]['kind'] !== 'folder') {
                    throw ValidationException::withMessages(['tracks' => 'Folder parents must exist, be folders and form an acyclic tree.']);
                }
                $visited[$parentId] = true;
                $parentId = $tracks[$parentId]['parent_id'];
            }
        }

        // Round-trip also rejects non-JSON values and excessive nesting. Preserve opaque
        // module state so an unavailable plugin or adapter never destroys saved content.
        $json = json_encode($document, JSON_THROW_ON_ERROR);
        if (strlen($json) > 16 * 1024 * 1024) {
            throw ValidationException::withMessages(['document' => 'Session manifests may not exceed 16 MiB.']);
        }

        return new self(json_decode($json, true, flags: JSON_THROW_ON_ERROR));
    }

    public static function empty(int $sampleRate = 48000): self
    {
        return self::fromArray(['schema_version' => 1, 'engine_version' => 'unassigned', 'sample_rate' => $sampleRate, 'tracks' => [], 'assets' => [], 'modules' => []]);
    }

    public function assets(): array
    {
        return $this->document['assets'];
    }

    public function jsonSerialize(): array
    {
        return $this->document;
    }

    public function checksum(): string
    {
        return hash('sha256', json_encode($this->document, JSON_THROW_ON_ERROR));
    }
}
