<?php

declare(strict_types=1);

namespace Liberu\Music\Projects\Commands;

use Illuminate\Support\Facades\Validator;
use Liberu\Music\Projects\Data\SessionDocument;

final readonly class SaveSession
{
    public function __construct(
        public string $workspaceId,
        public string $projectId,
        public string $title,
        public SessionDocument $document,
        public int $expectedVersion,
        public string $idempotencyKey,
        public bool $autosave = false,
        public bool $template = false,
    ) {
        Validator::make([
            'workspace_id' => $workspaceId, 'project_id' => $projectId, 'title' => $title,
            'expected_version' => $expectedVersion, 'idempotency_key' => $idempotencyKey,
        ], [
            'workspace_id' => ['required', 'string', 'max:128'],
            'project_id' => ['required', 'uuid'],
            'title' => ['required', 'string', 'max:255'],
            'expected_version' => ['required', 'integer:strict', 'min:0'],
            'idempotency_key' => ['required', 'string', 'max:128'],
        ])->validate();
    }
}
