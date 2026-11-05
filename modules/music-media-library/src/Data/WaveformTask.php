<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibrary\Data;

use JsonSerializable;

final readonly class WaveformTask implements JsonSerializable
{
    public function __construct(public string $id, public string $workspaceId, public string $assetId, public int $framesPerPeak, public string $status) {}

    public function jsonSerialize(): array
    {
        return ['schema_version' => 1, 'id' => $this->id, 'workspace_id' => $this->workspaceId,
            'asset_id' => $this->assetId, 'requested_sample_frames_per_peak' => $this->framesPerPeak, 'status' => $this->status];
    }
}
