<?php

declare(strict_types=1);

namespace Liberu\Music\Projects\Data;

use JsonSerializable;

final readonly class SessionImportPreview implements JsonSerializable
{
    public function __construct(public string $title, public SessionDocument $document) {}

    public function jsonSerialize(): array
    {
        $document = $this->document->jsonSerialize();

        return [
            'schema_version' => 1, 'title' => $this->title,
            'document_schema_version' => $document['schema_version'],
            'checksum' => $this->document->checksum(),
            'track_count' => count($document['tracks']), 'asset_count' => count($document['assets']),
            'module_names' => array_keys($document['modules']),
        ];
    }
}
