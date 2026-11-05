<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibrary\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\ConnectionInterface;
use Liberu\Music\MediaLibrary\Data\Waveform;
use Liberu\Music\MediaLibrary\Exceptions\InvalidAudio;

final readonly class AssetWaveforms
{
    public function __construct(private MediaUploads $uploads, private ConnectionInterface $database, private Filesystem $objects, private WaveformIndexer $indexer) {}

    public function build(Authenticatable $actor, string $workspaceId, string $assetId, int $framesPerPeak = 1024): Waveform
    {
        $asset = $this->uploads->get($actor, $workspaceId, $assetId);
        $key = $this->database->table('music_media_uploads')->where('workspace_id', $workspaceId)->where('id', $assetId)->where('status', 'ready')->value('blob_key');
        $stream = $this->objects->readStream($key);
        if (! is_resource($stream)) {
            throw new InvalidAudio('The verified audio could not be opened for waveform indexing.');
        }
        try {
            $waveform = $this->indexer->build($stream, $framesPerPeak);
            if (! hash_equals($asset->metadata['sha256'], $waveform->audio->sha256)) {
                throw new InvalidAudio('The stored audio differs from the verified asset.');
            }

            return $waveform;
        } finally {
            fclose($stream);
        }
    }
}
