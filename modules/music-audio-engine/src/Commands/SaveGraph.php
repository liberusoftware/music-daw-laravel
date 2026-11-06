<?php

declare(strict_types=1);

namespace Liberu\Music\AudioEngine\Commands;

use Illuminate\Support\Facades\Validator;
use Liberu\Music\AudioEngine\Data\GraphDefinition;

final readonly class SaveGraph
{
    public function __construct(public string $workspaceId, public string $projectId, public GraphDefinition $graph, public int $expectedVersion, public string $commandKey)
    {
        Validator::make(['workspace' => $workspaceId, 'project' => $projectId, 'version' => $expectedVersion, 'key' => $commandKey], [
            'workspace' => ['required', 'string', 'max:128'], 'project' => ['required', 'uuid'],
            'version' => ['required', 'integer:strict', 'min:1'], 'key' => ['required', 'string', 'max:96'],
        ])->validate();
    }
}
