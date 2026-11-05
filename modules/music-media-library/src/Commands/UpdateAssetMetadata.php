<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibrary\Commands;

use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

final readonly class UpdateAssetMetadata
{
    public array $tags;

    public function __construct(public string $workspaceId, public string $assetId, public string $name, array $tags, public int $expectedVersion, public string $commandKey)
    {
        Validator::make(['workspace' => $workspaceId, 'asset' => $assetId, 'name' => $name, 'tags' => $tags, 'version' => $expectedVersion, 'key' => $commandKey], [
            'workspace' => ['required', 'string', 'max:128'], 'asset' => ['required', 'uuid'], 'name' => ['required', 'string', 'max:255'],
            'tags' => ['array', 'list', 'max:32'], 'tags.*' => ['required', 'string', 'max:64'],
            'version' => ['integer:strict', 'min:0'], 'key' => ['required', 'string', 'max:128'],
        ])->validate();
        $normalized = array_values(array_unique(array_map(fn (string $tag): string => Str::lower(trim($tag)), $tags)));
        Validator::make(['tags' => $normalized], ['tags.*' => ['required', 'string', 'max:64']])->validate();
        sort($normalized, SORT_STRING);
        $this->tags = $normalized;
    }
}
