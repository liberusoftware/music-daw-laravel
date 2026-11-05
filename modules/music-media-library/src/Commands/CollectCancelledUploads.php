<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibrary\Commands;

use Illuminate\Support\Facades\Validator;

final readonly class CollectCancelledUploads
{
    public function __construct(
        public string $workspaceId,
        public int $graceHours = 24,
        public int $limit = 100,
        public int $afterChunkId = 0,
        public bool $dryRun = true,
    ) {
        Validator::make(['workspace' => $workspaceId, 'grace' => $graceHours, 'limit' => $limit, 'cursor' => $afterChunkId], [
            'workspace' => ['required', 'string', 'max:128'], 'grace' => ['integer:strict', 'between:24,8760'],
            'limit' => ['integer:strict', 'between:1,100'], 'cursor' => ['integer:strict', 'min:0'],
        ])->validate();
    }
}
