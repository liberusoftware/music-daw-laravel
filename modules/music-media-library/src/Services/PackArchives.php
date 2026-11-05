<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibrary\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\RecordNotFoundException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Liberu\Music\MediaLibrary\Commands\SaveMediaPack;
use Liberu\Music\MediaLibrary\Commands\StartUpload;
use Liberu\Music\MediaLibrary\Contracts\MediaAuthorizer;
use Liberu\Music\MediaLibrary\Contracts\ZipArchiveFactory;
use Liberu\Music\MediaLibrary\Data\ArchiveTransfer;
use Liberu\Music\MediaLibrary\Data\MediaPack;
use Liberu\Music\MediaLibrary\Exceptions\InvalidAudio;
use RuntimeException;
use ZipArchive;

final readonly class PackArchives
{
    private const int MANIFEST_BYTES = 8388608;

    public function __construct(private ConnectionInterface $database, private Filesystem $objects, private MediaAuthorizer $access, private MediaPacks $packs, private MediaUploads $uploads, private WaveInspector $inspector, private ZipArchiveFactory $archives, private int $maxAudioBytes = 1073741824)
    {
        Validator::make(['limit' => $maxAudioBytes], ['limit' => ['integer:strict', 'between:44,17179869184']])->validate();
    }

    /** @param resource $destination */
    public function exportTo(Authenticatable $actor, string $workspaceId, string $packId, mixed $destination, ?int $version = null): ArchiveTransfer
    {
        $manifest = $this->packs->export($actor, $workspaceId, $packId, $version);
        $this->assertStream($destination, true);
        $pack = MediaPack::fromArray(json_decode($manifest, true, flags: JSON_THROW_ON_ERROR)['document']);
        $ids = array_column(array_column($pack->assets, 'reference'), 'id');
        $rows = $this->database->table('music_media_uploads')->where('workspace_id', $workspaceId)->where('status', 'ready')->whereIn('id', $ids)
            ->get(['id', 'sha256', 'total_bytes', 'blob_key'])->keyBy('id');
        if ($rows->count() !== count($ids)) {
            throw new RecordNotFoundException('Every archived asset must remain ready in the requested workspace.');
        }
        $archive = tmpfile() ?: throw new RuntimeException('Private archive staging is unavailable.');
        $files = [];
        $open = false;
        $zip = null;
        try {
            $zip = $this->archives->open(stream_get_meta_data($archive)['uri'], ZipArchive::CREATE | ZipArchive::OVERWRITE);
            $open = true;
            $zip->addFromString('manifest.json', $manifest) ?: throw new RuntimeException('The pack manifest could not be archived.');
            $references = [];
            $bytes = 0;
            foreach ($pack->assets as $asset) {
                $this->access->authorize($actor, $workspaceId, $asset['reference']['id'], 'read');
                $this->access->authorize($actor, $workspaceId, $asset['reference']['id'], 'distribute');
                $row = $rows[$asset['reference']['id']];
                $reference = $asset['reference'];
                unset($reference['id']);
                $checksum = $reference['sha256'];
                $bytes += (int) $row->total_bytes;
                if ($bytes > $this->maxAudioBytes) {
                    throw ValidationException::withMessages(['archive' => 'Pack audio exceeds the configured archive byte limit.']);
                }
                if (isset($references[$checksum])) {
                    if ($references[$checksum] !== $reference) {
                        throw new InvalidAudio('Shared pack audio has inconsistent verified metadata.');
                    }

                    continue;
                }
                $file = $this->stageAudio($row, $reference);
                $files[] = $file;
                $name = 'assets/'.$checksum.'.wav';
                $zip->addFile(stream_get_meta_data($file)['uri'], $name) ?: throw new RuntimeException('Verified pack audio could not be archived.');
                $zip->setCompressionName($name, ZipArchive::CM_STORE) ?: throw new RuntimeException('The pack storage method could not be selected.');
                $references[$checksum] = $reference;
            }
            $zip->close() ?: throw new RuntimeException('The pack archive could not be finalized.');
            $open = false;
            // libzip may replace the staging inode when it closes the archive.
            $source = fopen(stream_get_meta_data($archive)['uri'], 'rb') ?: throw new RuntimeException('The completed archive is unreadable.');
            try {
                $length = fstat($source)['size'];
                $hash = hash_init('sha256');
                hash_update_stream($hash, $source);
                rewind($source);
                if (stream_copy_to_stream($source, $destination) !== $length) {
                    throw new RuntimeException('The archive destination did not accept every byte.');
                }

                return new ArchiveTransfer($workspaceId, $packId, $pack->version, $length, hash_final($hash), count($references));
            } finally {
                fclose($source);
            }
        } finally {
            try {
                if ($open && $zip !== null) {
                    $zip->close();
                }
            } finally {
                foreach ($files as $file) {
                    fclose($file);
                }
                fclose($archive);
            }
        }
    }

    /** @param resource $source */
    public function importFrom(Authenticatable $actor, string $workspaceId, string $packId, mixed $source, array $assetMap, string $commandKey): MediaPack
    {
        $this->access->authorize($actor, $workspaceId, $packId, 'pack_import');
        $this->assertStream($source, false);
        $archive = tmpfile() ?: throw new RuntimeException('Private archive staging is unavailable.');
        try {
            $limit = $this->maxAudioBytes + self::MANIFEST_BYTES * 2;
            $copied = stream_copy_to_stream($source, $archive, $limit + 1);
            if ($copied === false || $copied > $limit) {
                throw ValidationException::withMessages(['archive' => 'The incoming archive exceeds its byte limit or is unreadable.']);
            }
            $zip = $this->archives->open(stream_get_meta_data($archive)['uri'], ZipArchive::RDONLY | ZipArchive::CHECKCONS);
            try {
                $entries = $this->entries($zip);
                if (! isset($entries['manifest.json']) || $entries['manifest.json'] > self::MANIFEST_BYTES) {
                    throw ValidationException::withMessages(['archive' => 'A bounded pack manifest is required.']);
                }
                $manifest = @$zip->getFromName('manifest.json', self::MANIFEST_BYTES + 1);
                if ($manifest === false || strlen($manifest) !== $entries['manifest.json']) {
                    throw new InvalidAudio('The archive manifest is incomplete.');
                }
                $pack = $this->packs->previewImport($actor, $workspaceId, $packId, $manifest, $assetMap);
                new SaveMediaPack($workspaceId, $packId, $pack->name, array_values($assetMap), $pack->rights, 0, $commandKey);
                $this->validateContents($entries, $pack);
                $this->access->authorize($actor, $workspaceId, $packId, 'pack_write');
                $this->access->authorize($actor, $workspaceId, '*', 'retain');
                $packWorkspace = $this->database->table('music_media_packs')->where('id', $packId)->value('workspace_id');
                if ($packWorkspace !== null && $packWorkspace !== $workspaceId) {
                    throw new RecordNotFoundException('The destination pack identifier belongs to another workspace.');
                }
                foreach ($assetMap as $targetId) {
                    $this->access->authorize($actor, $workspaceId, $targetId, 'upload');
                }
                $existing = $this->database->table('music_media_uploads')->whereIn('id', array_values($assetMap))->get(['id', 'workspace_id', 'status'])->keyBy('id');
                foreach ($existing as $row) {
                    if ($row->workspace_id !== $workspaceId) {
                        throw new RecordNotFoundException('A destination upload identifier belongs to another workspace.');
                    }
                }
                foreach ($pack->assets as $asset) {
                    $id = $assetMap[$asset['reference']['id']];
                    $entry = 'assets/'.$asset['reference']['sha256'].'.wav';
                    if (isset($existing[$id]) && $existing[$id]->status === 'ready') {
                        $this->uploads->complete($actor, $workspaceId, $id);

                        continue;
                    }
                    $progress = $this->uploads->start($actor, new StartUpload($workspaceId, $id, $asset['name'], $entries[$entry], $asset['reference']['sha256'], $asset['provenance']));
                    if ($progress->status !== 'ready') {
                        $this->receive($actor, $workspaceId, $id, $zip, $entry, $entries[$entry]);
                    }
                    $this->uploads->complete($actor, $workspaceId, $id);
                }

                return $this->packs->import($actor, $workspaceId, $packId, $manifest, $assetMap, $commandKey);
            } finally {
                $zip->close();
            }
        } finally {
            fclose($archive);
        }
    }

    private function assertStream(mixed $stream, bool $writable): void
    {
        if (! is_resource($stream) || get_resource_type($stream) !== 'stream'
            || ! preg_match($writable ? '/[waxc+]/' : '/[r+]/', stream_get_meta_data($stream)['mode'])) {
            throw ValidationException::withMessages(['stream' => 'An open '.($writable ? 'writable' : 'readable').' stream is required.']);
        }
    }

    /** @return array<string, int> */
    private function entries(ZipArchive $zip): array
    {
        $entries = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $entry = $zip->statIndex($index) ?: throw new InvalidAudio('An archive entry is unreadable.');
            if (isset($entries[$entry['name']]) || $entry['encryption_method'] !== ZipArchive::EM_NONE
                || ! in_array($entry['comp_method'], [ZipArchive::CM_STORE, ZipArchive::CM_DEFLATE], true)) {
                throw ValidationException::withMessages(['archive' => 'Duplicate, encrypted or unsupported entries are not accepted.']);
            }
            $entries[$entry['name']] = $entry['size'];
        }

        return $entries;
    }

    private function validateContents(array $entries, MediaPack $pack): void
    {
        $expected = ['manifest.json'];
        foreach ($pack->assets as $asset) {
            $expected[] = 'assets/'.$asset['reference']['sha256'].'.wav';
        }
        $expected = array_values(array_unique($expected));
        $names = array_keys($entries);
        sort($expected, SORT_STRING);
        sort($names, SORT_STRING);
        if ($names !== $expected) {
            throw ValidationException::withMessages(['archive' => 'Archive entries must exactly match the manifest audio objects.']);
        }
        $bytes = 0;
        foreach ($entries as $name => $size) {
            if ($name === 'manifest.json') {
                continue;
            }
            if ($size < 44 || $size > 1073741824) {
                throw ValidationException::withMessages(['archive' => 'An audio object is outside the verified upload size limit.']);
            }
        }
        foreach ($pack->assets as $asset) {
            $bytes += $entries['assets/'.$asset['reference']['sha256'].'.wav'];
        }
        if ($bytes > $this->maxAudioBytes) {
            throw ValidationException::withMessages(['archive' => 'Uncompressed audio exceeds the configured archive byte limit.']);
        }
    }

    /** @param array{sha256: string, sample_rate: int, channels: int, frames: int} $reference
     * @return resource
     */
    private function stageAudio(object $row, array $reference): mixed
    {
        $source = $this->objects->readStream($row->blob_key);
        if (! is_resource($source)) {
            throw new InvalidAudio('A verified pack object is unreadable.');
        }
        $file = tmpfile() ?: throw new RuntimeException('Private audio staging is unavailable.');
        try {
            if (stream_copy_to_stream($source, $file, (int) $row->total_bytes + 1) !== (int) $row->total_bytes) {
                throw new InvalidAudio('The stored pack object size differs from its verification.');
            }
            $audio = $this->inspector->inspect($file);
            if ($audio->sha256 !== $reference['sha256'] || $audio->sampleRate !== $reference['sample_rate'] || $audio->channels !== $reference['channels'] || $audio->frames !== $reference['frames']) {
                throw new InvalidAudio('The stored pack object differs from its verified reference.');
            }

            return $file;
        } catch (\Throwable $error) {
            fclose($file);
            throw $error;
        } finally {
            fclose($source);
        }
    }

    private function receive(Authenticatable $actor, string $workspaceId, string $uploadId, ZipArchive $zip, string $entry, int $bytes): void
    {
        $source = $zip->getStream($entry) ?: throw new InvalidAudio('An archived audio stream is unavailable.');
        try {
            $offset = 0;
            while ($offset < $bytes) {
                $chunk = @fread($source, min(1048576, $bytes - $offset));
                if ($chunk === false || $chunk === '') {
                    throw new InvalidAudio('The archived audio stream is incomplete.');
                }
                $this->uploads->append($actor, $workspaceId, $uploadId, $offset, $chunk);
                $offset += strlen($chunk);
            }
            if (@fread($source, 1) !== '') {
                throw new InvalidAudio('The archived audio stream exceeds its size or failed integrity verification.');
            }
        } finally {
            fclose($source);
        }
    }
}
