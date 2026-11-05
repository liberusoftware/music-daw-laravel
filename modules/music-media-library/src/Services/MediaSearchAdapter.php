<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibrary\Services;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Factory;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Validator;
use Liberu\Music\MediaLibrary\Commands\SearchAssets;
use Liberu\Music\MediaLibrary\Data\CatalogEntry;

final readonly class MediaSearchAdapter
{
    public function __construct(private Factory $auth, private MediaCatalog $catalog) {}

    /** @return LengthAwarePaginator<int, CatalogEntry> */
    public function __invoke(array $filters): LengthAwarePaginator
    {
        $actor = $this->auth->guard()->user();
        if ($actor === null) {
            throw new AuthenticationException();
        }
        $validated = Validator::make($filters, [
            'workspace_id' => ['sometimes', 'required', 'string', 'max:128'], 'query' => ['sometimes', 'nullable', 'string', 'max:200'],
            'tags' => ['sometimes', 'array', 'list', 'max:16'], 'tags.*' => ['required', 'string', 'max:64'],
            'sha256' => ['nullable', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'page' => ['sometimes', 'integer', 'between:1,100000'], 'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ])->validate();

        if (! isset($validated['workspace_id'])) {
            return new LengthAwarePaginator([], 0, (int) ($validated['per_page'] ?? 25), (int) ($validated['page'] ?? 1));
        }

        return $this->catalog->search($actor, new SearchAssets($validated['workspace_id'], $validated['query'] ?? '', $validated['tags'] ?? [],
            $validated['sha256'] ?? null, (int) ($validated['page'] ?? 1), (int) ($validated['per_page'] ?? 25)));
    }
}
