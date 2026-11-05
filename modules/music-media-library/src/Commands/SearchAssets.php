<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibrary\Commands;

use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

final readonly class SearchAssets
{
    public array $tags;

    public function __construct(public string $workspaceId, public string $query = '', array $tags = [], public ?string $sha256 = null, public int $page = 1, public int $perPage = 25)
    {
        Validator::make(['workspace' => $workspaceId, 'query' => $query, 'tags' => $tags, 'sha256' => $sha256, 'page' => $page, 'per_page' => $perPage], [
            'workspace' => ['required', 'string', 'max:128'], 'query' => ['string', 'max:200'],
            'tags' => ['array', 'list', 'max:16'], 'tags.*' => ['required', 'string', 'max:64'],
            'sha256' => ['nullable', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'page' => ['integer:strict', 'between:1,100000'], 'per_page' => ['integer:strict', 'between:1,100'],
        ])->validate();
        $normalized = array_values(array_unique(array_map(fn (string $tag): string => Str::lower(trim($tag)), $tags)));
        Validator::make(['tags' => $normalized], ['tags.*' => ['required', 'string', 'max:64']])->validate();
        $this->tags = $normalized;
    }
}
