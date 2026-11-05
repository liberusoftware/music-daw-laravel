<?php

declare(strict_types=1);

namespace Liberu\Music\Projects\Commands;

use Illuminate\Support\Facades\Validator;

final readonly class BackfillSessionRetention
{
    public function __construct(public string $workspaceId, public int $limit = 10, public bool $restart = false)
    {
        Validator::make(['workspace' => $workspaceId, 'limit' => $limit], [
            'workspace' => ['required', 'string', 'max:128'], 'limit' => ['integer:strict', 'between:1,25'],
        ])->validate();
    }
}
