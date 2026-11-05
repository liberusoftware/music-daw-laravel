<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibraryApi\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

final class BrowseMediaRequest extends FormRequest
{
    public function rules(): array
    {
        return ['filter' => ['sometimes', 'array:query,tags,sha256'], 'filter.query' => ['sometimes', 'string', 'max:200'],
            'filter.tags' => ['sometimes', 'array', 'list', 'max:16'], 'filter.tags.*' => ['required', 'string', 'max:64'],
            'filter.sha256' => ['sometimes', 'string', 'regex:/^[a-f0-9]{64}$/D'],
            'page' => ['sometimes', 'array:size,after'], 'page.size' => ['sometimes', 'integer', 'between:1,100'], 'page.after' => ['sometimes', 'string', 'max:4096']];
    }

    public function filters(): array
    {
        $tags = array_values(array_unique(array_map(fn (string $tag): string => Str::lower(trim($tag)), $this->validated('filter.tags', []))));
        sort($tags, SORT_STRING);

        return ['query' => $this->validated('filter.query', ''), 'tags' => $tags, 'sha256' => $this->validated('filter.sha256'), 'limit' => (int) $this->validated('page.size', 25)];
    }
}
