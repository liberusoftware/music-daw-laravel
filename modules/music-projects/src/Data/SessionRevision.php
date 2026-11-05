<?php

declare(strict_types=1);

namespace Liberu\Music\Projects\Data;

use JsonSerializable;

final readonly class SessionRevision implements JsonSerializable
{
    public function __construct(
        public string $workspaceId,
        public string $projectId,
        public string $title,
        public int $version,
        public SessionDocument $document,
        public bool $autosave,
        public bool $template,
    ) {}

    public function jsonSerialize(): array
    {
        return [
            'workspace_id' => $this->workspaceId, 'project_id' => $this->projectId,
            'title' => $this->title, 'version' => $this->version,
            'document' => $this->document->jsonSerialize(), 'autosave' => $this->autosave,
            'template' => $this->template,
        ];
    }
}
