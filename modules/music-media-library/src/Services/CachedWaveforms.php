<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibrary\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Cache\Repository;
use InvalidArgumentException;
use Liberu\Music\MediaLibrary\Data\Waveform;

/** Derived previews only; never a replacement for verified audio or authorization. */
final readonly class CachedWaveforms
{
    public function __construct(private MediaUploads $uploads, private AssetWaveforms $waveforms, private Repository $cache) {}

    public function get(Authenticatable $actor, string $workspaceId, string $assetId, int $framesPerPeak = 1024): ?Waveform
    {
        $key = $this->key($actor, $workspaceId, $assetId, $framesPerPeak);
        $result = $this->cache->get($key);

        return $result instanceof Waveform ? $result : null;
    }

    public function build(Authenticatable $actor, string $workspaceId, string $assetId, int $framesPerPeak = 1024): Waveform
    {
        $key = $this->key($actor, $workspaceId, $assetId, $framesPerPeak);

        return $this->cache->remember($key, 86400, fn (): Waveform => $this->waveforms->build($actor, $workspaceId, $assetId, $framesPerPeak));
    }

    private function key(Authenticatable $actor, string $workspaceId, string $assetId, int $framesPerPeak): string
    {
        if ($framesPerPeak < 1 || $framesPerPeak > 1048576) {
            throw new InvalidArgumentException('Waveform windows must contain between 1 and 1048576 sample frames.');
        }
        $asset = $this->uploads->get($actor, $workspaceId, $assetId);

        return 'music:waveform:v1:'.hash('sha256', $workspaceId).':'.$asset->metadata['sha256'].':'.$framesPerPeak;
    }
}
