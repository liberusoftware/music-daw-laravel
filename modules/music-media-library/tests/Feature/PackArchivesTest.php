<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\RecordNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Liberu\Foundation\Files\Contracts\MalwareScanner;
use Liberu\Foundation\ImportExport\Contracts\TransferAuthorizer;
use Liberu\Music\MediaLibrary\Commands\SaveMediaPack;
use Liberu\Music\MediaLibrary\Commands\StartUpload;
use Liberu\Music\MediaLibrary\Contracts\MediaAuthorizer;
use Liberu\Music\MediaLibrary\Contracts\ZipArchiveFactory;
use Liberu\Music\MediaLibrary\Exceptions\InvalidAudio;
use Liberu\Music\MediaLibrary\Exceptions\UploadBudgetExceeded;
use Liberu\Music\MediaLibrary\Services\MediaPacks;
use Liberu\Music\MediaLibrary\Services\MediaUploads;
use Liberu\Music\MediaLibrary\Services\PackArchives;
use Liberu\Music\MediaLibrary\Services\WaveInspector;
use Liberu\Music\MediaLibrary\Support\ZipDirectoryLimits;
use Liberu\Music\MediaLibrary\Tests\Fixtures\ChangingAudioStream;
use Liberu\Music\MediaLibrary\Tests\Fixtures\RejectingArchiveStream;
use Liberu\Music\MediaLibrary\Tests\Fixtures\UnreliableZipArchive;

beforeEach(function () {
    $this->artisan('migrate', ['--path' => realpath(__DIR__.'/../../database/migrations'), '--realpath' => true])->assertSuccessful();
    config()->set('music-media.disk', 'pack-archives-test');
    $this->disk = Storage::fake('pack-archives-test');
    $this->actor = new GenericUser(['id' => 1]);
    foreach (['upload', 'read', 'retain', 'pack_write', 'pack_read', 'pack_export', 'pack_import', 'distribute'] as $operation) {
        app(Gate::class)->define('music.media.'.$operation, fn ($actor, $workspace) => $actor->id === 1 && in_array($workspace, ['studio', 'other']));
    }
    app(Gate::class)->define('music.media.pack_distribute', fn ($actor, $pack) => $actor->id === 1 && in_array($pack->workspaceId, ['studio', 'other']));
    $scanner = Mockery::mock(MalwareScanner::class);
    $scanner->shouldReceive('clean')->andReturn(true);
    app()->instance(MalwareScanner::class, $scanner);
    $transfers = Mockery::mock(TransferAuthorizer::class);
    $transfers->shouldReceive('allowed')->with('1', 'music-media-pack', Mockery::any())->andReturn(true);
    app()->instance(TransferAuthorizer::class, $transfers);
    $this->uploads = app(MediaUploads::class);
    $this->packs = app(MediaPacks::class);
    $this->archives = app(PackArchives::class);
    $this->packId = (string) Str::uuid();
    $this->rights = ['source' => 'Original studio recordings', 'license' => 'CC-BY-4.0', 'attribution' => 'Studio artist'];
    $this->streams = [];
    $this->stream = function (string $bytes = '') {
        $stream = tmpfile();
        fwrite($stream, $bytes);
        rewind($stream);
        $this->streams[] = $stream;

        return $stream;
    };
    $this->wave = function (int $sample = 16384): string {
        $format = pack('vvVVvv', 1, 1, 48000, 96000, 2, 16);
        $data = 'fmt '.pack('V', 16).$format.'data'.pack('V', 4).pack('v*', 0, $sample);

        return 'RIFF'.pack('V', strlen($data) + 4).'WAVE'.$data;
    };
    $this->publish = function (string $workspace = 'studio', int $sample = 16384): string {
        $bytes = ($this->wave)($sample);
        $id = (string) Str::uuid();
        $this->uploads->start($this->actor, new StartUpload($workspace, $id, 'Piano take', strlen($bytes), hash('sha256', $bytes), $this->rights));
        $this->uploads->append($this->actor, $workspace, $id, 0, $bytes);
        $this->uploads->complete($this->actor, $workspace, $id);

        return $id;
    };
    $this->save = fn (array $ids) => $this->packs->save($this->actor, new SaveMediaPack('studio', $this->packId, 'Studio pack', $ids, $this->rights, 0, 'create'));
    $this->export = function () {
        $stream = ($this->stream)();
        $this->archives->exportTo($this->actor, 'studio', $this->packId, $stream);
        rewind($stream);

        return $stream;
    };
    $this->zip = function (array $entries, ?Closure $configure = null) {
        $file = ($this->stream)();
        $path = stream_get_meta_data($file)['uri'];
        $zip = new ZipArchive();
        expect($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE))->toBeTrue();
        foreach ($entries as $name => $bytes) {
            $zip->addFromString($name, $bytes);
            $zip->setCompressionName($name, ZipArchive::CM_STORE);
        }
        if ($configure !== null) {
            $configure($zip);
        }
        expect($zip->close())->toBeTrue();
        $reader = fopen($path, 'rb');
        $this->streams[] = $reader;

        return $reader;
    };
    $this->entries = function (): array {
        $manifest = $this->packs->export($this->actor, 'studio', $this->packId);
        $asset = json_decode($manifest, true)['document']['assets'][0];

        return ['manifest.json' => $manifest, 'assets/'.$asset['reference']['sha256'].'.wav' => ($this->wave)()];
    };
});

afterEach(function () {
    foreach ($this->streams as $stream) {
        if (is_resource($stream)) {
            fclose($stream);
        }
    }
});

it('exports reviewed packs with deduplicated verified bytes and returns a public checksum receipt', function () {
    $first = ($this->publish)();
    $second = ($this->publish)();
    ($this->save)([$first, $second]);
    $stream = ($this->stream)();
    $receipt = $this->archives->exportTo($this->actor, 'studio', $this->packId, $stream, 1);
    rewind($stream);
    $bytes = stream_get_contents($stream);
    expect($receipt->jsonSerialize())->toBe(['schema_version' => 1, 'workspace_id' => 'studio', 'pack_id' => $this->packId,
        'version' => 1, 'bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'media_objects' => 1]);
    $zip = new ZipArchive();
    $file = ($this->stream)($bytes);
    expect($zip->open(stream_get_meta_data($file)['uri']))->toBeTrue()->and($zip->numFiles)->toBe(2);
    expect($zip->getFromName('assets/'.hash('sha256', ($this->wave)()).'.wav'))->toBe(($this->wave)());
    $manifest = json_decode($zip->getFromName('manifest.json'), true);
    expect($manifest['document']['rights'])->toBe($this->rights)
        ->and($manifest['document']['assets'])->toHaveCount(2)
        ->and($manifest['document']['assets'][0]['provenance']['attribution'])->toBe('Studio artist');
    $zip->close();
    expect(json_encode($receipt))->not->toContain('blob_key', '/tmp/', 'music/assets/');
});

it('imports exported audio into another workspace and replays using the same destination IDs', function () {
    $id = ($this->publish)();
    $original = ($this->save)([$id]);
    $source = ($this->export)();
    $newId = (string) Str::uuid();
    $newPack = (string) Str::uuid();
    $imported = $this->archives->importFrom($this->actor, 'other', $newPack, $source, [$id => $newId], 'receive');
    expect($imported->workspaceId)->toBe('other')->and($imported->version)->toBe(1)->and($imported->rights)->toBe($original->rights)
        ->and($imported->assets[0]['reference']['id'])->toBe($newId)
        ->and($imported->assets[0]['provenance'])->toBe($original->assets[0]['provenance'])
        ->and($this->uploads->get($this->actor, 'other', $newId)->metadata['sha256'])->toBe(hash('sha256', ($this->wave)()));
    rewind($source);
    expect($this->archives->importFrom($this->actor, 'other', $newPack, $source, [$id => $newId], 'receive')->jsonSerialize())->toBe($imported->jsonSerialize())
        ->and(DB::table('music_media_pack_revisions')->count())->toBe(2)->and(DB::table('music_media_uploads')->count())->toBe(2);
    rewind($source);
    expect(fn () => $this->archives->importFrom($this->actor, 'other', (string) Str::uuid(), $source, [$id => $id], 'cross'))->toThrow(RecordNotFoundException::class);
});

it('reuses matching verified destination assets without rewriting their upload request provenance order', function () {
    $id = ($this->publish)();
    ($this->save)([$id]);
    $source = ($this->export)();
    $pack = $this->archives->importFrom($this->actor, 'studio', (string) Str::uuid(), $source, [$id => $id], 'reuse');
    expect($pack->assets[0]['reference']['id'])->toBe($id)->and(DB::table('music_media_uploads')->count())->toBe(1);
});

it('retains resumable quarantine when scanning fails and resumes only identical upload bytes', function () {
    $id = ($this->publish)();
    ($this->save)([$id]);
    $source = ($this->export)();
    $scanner = Mockery::mock(MalwareScanner::class);
    $scanner->shouldReceive('clean')->twice()->andReturn(false, true);
    app()->instance(MalwareScanner::class, $scanner);
    $archives = app(PackArchives::class);
    $newId = (string) Str::uuid();
    $packId = (string) Str::uuid();
    expect(fn () => $archives->importFrom($this->actor, 'other', $packId, $source, [$id => $newId], 'retry'))->toThrow(InvalidAudio::class, 'scanner');
    expect($this->uploads->status($this->actor, 'other', $newId)->status)->toBe('uploading')
        ->and(DB::table('music_media_packs')->count())->toBe(1);
    rewind($source);
    $result = $archives->importFrom($this->actor, 'other', $packId, $source, [$id => $newId], 'retry');
    expect($result->assets[0]['reference']['id'])->toBe($newId)
        ->and(DB::table('music_media_upload_chunks')->where('upload_id', $newId)->count())->toBe(1);
});

it('checks export rights before writing anything and rejects denied import before persisting uploads', function () {
    $id = ($this->publish)();
    ($this->save)([$id]);
    $source = ($this->export)();
    $destination = ($this->stream)();
    $other = new GenericUser(['id' => 2]);
    expect(fn () => $this->archives->exportTo($other, 'studio', $this->packId, $destination))->toThrow(AuthorizationException::class);
    expect(fn () => $this->archives->importFrom($other, 'other', (string) Str::uuid(), $source, [$id => (string) Str::uuid()], 'denied'))->toThrow(AuthorizationException::class);
    app(Gate::class)->define('music.media.pack_distribute', fn () => false);
    expect(fn () => $this->archives->exportTo($this->actor, 'studio', $this->packId, $destination))->toThrow(AuthorizationException::class);
    app()->forgetInstance(TransferAuthorizer::class);
    expect(fn () => app(PackArchives::class)->importFrom($this->actor, 'other', (string) Str::uuid(), $source, [$id => (string) Str::uuid()], 'missing'))->toThrow(AuthorizationException::class, 'foundation');
    expect(ftell($destination))->toBe(0)->and(DB::table('music_media_uploads')->count())->toBe(1);
});

it('preflights every destination upload permission before ingesting an asset', function () {
    $id = ($this->publish)();
    ($this->save)([$id]);
    $source = ($this->export)();
    app(Gate::class)->define('music.media.upload', fn () => false);
    expect(fn () => $this->archives->importFrom($this->actor, 'other', (string) Str::uuid(), $source, [$id => (string) Str::uuid()], 'denied'))->toThrow(AuthorizationException::class);
    expect(DB::table('music_media_uploads')->count())->toBe(1);
});

it('rejects extra traversal missing encrypted and duplicate entries before creating destination uploads', function (string $kind) {
    $id = ($this->publish)();
    ($this->save)([$id]);
    $entries = ($this->entries)();
    $audio = array_key_last($entries);
    $configure = null;
    if ($kind === 'extra') {
        $entries['../outside.wav'] = 'not an allowed object';
    } elseif ($kind === 'missing') {
        unset($entries[$audio]);
        $entries['unrelated.wav'] = ($this->wave)();
    } elseif ($kind === 'encrypted') {
        $configure = fn (ZipArchive $zip) => expect($zip->setEncryptionName('manifest.json', ZipArchive::EM_AES_256, 'secret'))->toBeTrue();
    } else {
        $alias = substr($audio, 0, -5).($audio[-5] === 'a' ? 'b' : 'a').'.wav';
        $entries[$alias] = ($this->wave)();
    }
    $source = ($this->zip)($entries, $configure);
    if ($kind === 'duplicate') {
        $source = ($this->stream)(str_replace($alias, $audio, stream_get_contents($source)));
    }
    expect(fn () => $this->archives->importFrom($this->actor, 'other', (string) Str::uuid(), $source, [$id => (string) Str::uuid()], 'bad-entries'))->toThrow(ValidationException::class);
    expect(DB::table('music_media_uploads')->count())->toBe(1);
})->with(['extra', 'missing', 'encrypted', 'duplicate']);

it('rejects bad ZIP bytes excessive entry counts and missing or oversized manifests', function (string $kind) {
    $id = ($this->publish)();
    ($this->save)([$id]);
    $entries = ($this->entries)();
    if ($kind === 'missing') {
        $entries['not-manifest'] = $entries['manifest.json'];
        unset($entries['manifest.json']);
    } elseif ($kind === 'oversized') {
        $entries['manifest.json'] = str_repeat('x', 8388609);
    } elseif ($kind === 'count') {
        for ($index = 0; $index < 1001; $index++) {
            $entries['extra-'.$index] = '';
        }
    }
    $source = $kind === 'invalid' ? ($this->stream)('not a ZIP file') : ($this->zip)($entries);
    expect(fn () => $this->archives->importFrom($this->actor, 'other', (string) Str::uuid(), $source, [$id => (string) Str::uuid()], 'bad-zip'))->toThrow(ValidationException::class);
})->with(['invalid', 'missing', 'oversized', 'count']);

it('bounds compressed input and uncompressed audio before ingesting any bytes', function () {
    $id = ($this->publish)();
    ($this->save)([$id]);
    $source = ($this->export)();
    config()->set('music-media.archive_max_audio_bytes', 44);
    $limited = app(PackArchives::class);
    expect(fn () => $limited->importFrom($this->actor, 'other', (string) Str::uuid(), $source, [$id => (string) Str::uuid()], 'large-audio'))->toThrow(ValidationException::class, 'Uncompressed');
    $oversized = ($this->stream)();
    ftruncate($oversized, 16777261);
    rewind($oversized);
    expect(fn () => $limited->importFrom($this->actor, 'other', (string) Str::uuid(), $oversized, [$id => (string) Str::uuid()], 'large-input'))->toThrow(ValidationException::class, 'byte limit');
    $output = ($this->stream)();
    expect(fn () => $limited->exportTo($this->actor, 'studio', $this->packId, $output))->toThrow(ValidationException::class, 'byte limit');
    expect(ftell($output))->toBe(0)->and(DB::table('music_media_uploads')->count())->toBe(1);
});

it('refuses undersized audio entries and mismatching verified bytes', function (bool $undersized) {
    $id = ($this->publish)();
    ($this->save)([$id]);
    $entries = ($this->entries)();
    $entries[array_key_last($entries)] = $undersized ? 'short' : ($this->wave)(1);
    $source = ($this->zip)($entries);
    $action = fn () => $this->archives->importFrom($this->actor, 'other', (string) Str::uuid(), $source, [$id => (string) Str::uuid()], 'bad-audio');
    expect($action)->toThrow($undersized ? ValidationException::class : InvalidAudio::class);
    expect(DB::table('music_media_packs')->count())->toBe(1);
})->with([true, false]);

it('fails without emitting a binary archive when stored bytes have changed size or content', function (bool $truncate) {
    $id = ($this->publish)();
    ($this->save)([$id]);
    $key = DB::table('music_media_uploads')->where('id', $id)->value('blob_key');
    $this->disk->put($key, $truncate ? 'short' : ($this->wave)(1));
    $destination = ($this->stream)();
    expect(fn () => $this->archives->exportTo($this->actor, 'studio', $this->packId, $destination))->toThrow(InvalidAudio::class);
    expect(ftell($destination))->toBe(0);
})->with([true, false]);

it('rejects contradictory metadata for aliases sharing one content address', function () {
    $ids = [($this->publish)(), ($this->publish)()];
    sort($ids, SORT_STRING);
    $metadata = json_decode(DB::table('music_media_uploads')->where('id', $ids[1])->value('metadata'), true);
    $metadata['frames']++;
    DB::table('music_media_uploads')->where('id', $ids[1])->update(['metadata' => json_encode($metadata)]);
    ($this->save)($ids);
    expect(fn () => $this->archives->exportTo($this->actor, 'studio', $this->packId, ($this->stream)()))->toThrow(InvalidAudio::class, 'inconsistent');
});

it('fails closed if an asset ceases to be ready between manifest review and object staging', function () {
    $id = ($this->publish)();
    ($this->save)([$id]);
    app(Gate::class)->define('music.media.distribute', function () use ($id) {
        DB::table('music_media_uploads')->where('id', $id)->update(['status' => 'cancelled']);

        return true;
    });
    expect(fn () => $this->archives->exportTo($this->actor, 'studio', $this->packId, ($this->stream)()))->toThrow(RecordNotFoundException::class);
});

it('reports an unreadable storage stream without returning an archive receipt', function () {
    $id = ($this->publish)();
    ($this->save)([$id]);
    $objects = Mockery::mock(Filesystem::class);
    $objects->shouldReceive('readStream')->once()->andReturn(false);
    $archives = new PackArchives(DB::connection(), $objects, app(MediaAuthorizer::class), $this->packs, $this->uploads, app(WaveInspector::class), app(ZipArchiveFactory::class));
    expect(fn () => $archives->exportTo($this->actor, 'studio', $this->packId, ($this->stream)()))->toThrow(InvalidAudio::class, 'unreadable');
});

it('validates resource direction and the configured archive limit', function () {
    $id = ($this->publish)();
    ($this->save)([$id]);
    expect(fn () => $this->archives->exportTo($this->actor, 'studio', $this->packId, null))->toThrow(ValidationException::class, 'writable');
    expect(fn () => $this->archives->importFrom($this->actor, 'other', (string) Str::uuid(), 'path.zip', [$id => (string) Str::uuid()], 'string'))->toThrow(ValidationException::class, 'readable');
    $file = ($this->stream)();
    $reader = fopen(stream_get_meta_data($file)['uri'], 'rb');
    $this->streams[] = $reader;
    expect(fn () => $this->archives->exportTo($this->actor, 'studio', $this->packId, $reader))->toThrow(ValidationException::class, 'writable');
    config()->set('music-media.archive_max_audio_bytes', 43);
    expect(fn () => app(PackArchives::class))->toThrow(ValidationException::class);
});

it('does not acknowledge an archive destination that refuses bytes', function () {
    $id = ($this->publish)();
    ($this->save)([$id]);
    stream_wrapper_register('musicarchivefail', RejectingArchiveStream::class);
    $destination = fopen('musicarchivefail://output', 'wb');
    try {
        expect(fn () => $this->archives->exportTo($this->actor, 'studio', $this->packId, $destination))->toThrow(RuntimeException::class, 'every byte');
    } finally {
        fclose($destination);
        stream_wrapper_unregister('musicarchivefail');
    }
});

it('rejects broken ZIP entry readers without publishing an incomplete imported pack', function (string $fault) {
    $id = ($this->publish)();
    ($this->save)([$id]);
    $source = ($this->export)();
    $factory = Mockery::mock(ZipArchiveFactory::class);
    $factory->shouldReceive('open')->once()->andReturnUsing(function (string $path, int $flags) use ($fault) {
        $zip = new UnreliableZipArchive();
        expect($zip->open($path, $flags))->toBeTrue();
        $zip->fault = $fault;
        $zip->audio = ($this->wave)();

        return $zip;
    });
    app()->instance(ZipArchiveFactory::class, $factory);
    $archives = app(PackArchives::class);
    expect(fn () => $archives->importFrom($this->actor, 'other', (string) Str::uuid(), $source, [$id => (string) Str::uuid()], 'broken'))->toThrow(InvalidAudio::class);
    expect(DB::table('music_media_packs')->count())->toBe(1)
        ->and(DB::table('music_media_uploads')->where('workspace_id', 'other')->where('status', 'ready')->count())->toBe(0);
})->with(['manifest', 'incomplete', 'extra']);

it('counts aliases against the audio budget so deduplication cannot amplify import storage', function () {
    $ids = [($this->publish)(), ($this->publish)()];
    ($this->save)($ids);
    $source = ($this->export)();
    config()->set('music-media.archive_max_audio_bytes', 60);
    $limited = app(PackArchives::class);
    $map = array_combine($ids, [(string) Str::uuid(), (string) Str::uuid()]);
    expect(fn () => $limited->importFrom($this->actor, 'other', (string) Str::uuid(), $source, $map, 'aliases'))->toThrow(ValidationException::class, 'Uncompressed');
    expect(fn () => $limited->exportTo($this->actor, 'studio', $this->packId, ($this->stream)()))->toThrow(ValidationException::class, 'byte limit');
    expect(DB::table('music_media_uploads')->count())->toBe(2);
});

it('accepts bounded single disk ZIP64 directory declarations and ordinary archive comments', function (bool $zip64) {
    $id = ($this->publish)();
    ($this->save)([$id]);
    $source = ($this->export)();
    $bytes = stream_get_contents($source);
    $position = strlen($bytes) - 22;
    $end = unpack('vdisk/vstart/vdisk_entries/ventries/Vbytes/Voffset/vcomment', substr($bytes, $position + 4));
    if ($zip64) {
        $large = "PK\x06\x06".pack('PvvVVP4', 44, 45, 45, 0, 0, $end['entries'], $end['entries'], $end['bytes'], $end['offset']);
        $locator = "PK\x06\x07".pack('VPV', 0, $position, 1);
        $footer = "PK\x05\x06".pack('vvvvVVv', 0, 0, 65535, 65535, 4294967295, 4294967295, 0);
        $bytes = substr($bytes, 0, $position).$large.$locator.$footer;
    } else {
        $bytes = substr($bytes, 0, -2).pack('v', 12).'archive note';
    }
    $imported = $this->archives->importFrom($this->actor, 'other', (string) Str::uuid(), ($this->stream)($bytes), [$id => (string) Str::uuid()], 'directory');
    expect($imported->assets)->toHaveCount(1);
})->with([true, false]);

it('rejects oversized split ambiguous or damaged directory declarations before native entry processing', function (string $fault) {
    $id = ($this->publish)();
    ($this->save)([$id]);
    $source = ($this->export)();
    $bytes = stream_get_contents($source);
    $position = strlen($bytes) - 22;
    if ($fault === 'directory-size') {
        $bytes = substr_replace($bytes, pack('V', 1048577), $position + 12, 4);
    } elseif ($fault === 'split') {
        $bytes = substr_replace($bytes, pack('v', 1), $position + 4, 2);
    } elseif ($fault === 'missing-zip64') {
        $bytes = substr_replace($bytes, pack('vv', 65535, 65535), $position + 8, 4);
    } elseif ($fault === 'ambiguous') {
        $bytes = substr_replace($bytes, pack('v', 22), $position + 20, 2).substr($bytes, $position, 22);
    } elseif ($fault === 'native-directory') {
        $bytes = substr_replace($bytes, 'BAD!', strpos($bytes, "PK\x01\x02"), 4);
    } else {
        $bytes = '';
    }
    expect(fn () => $this->archives->importFrom($this->actor, 'other', (string) Str::uuid(), ($this->stream)($bytes), [$id => (string) Str::uuid()], 'bad-directory'))->toThrow(ValidationException::class);
    expect(DB::table('music_media_uploads')->count())->toBe(1);
})->with(['directory-size', 'split', 'missing-zip64', 'ambiguous', 'native-directory', 'empty']);

it('rejects inconsistent and out of range ZIP64 records', function (string $fault) {
    $id = ($this->publish)();
    ($this->save)([$id]);
    $source = ($this->export)();
    $bytes = stream_get_contents($source);
    $position = strlen($bytes) - 22;
    $end = unpack('vdisk/vstart/vdisk_entries/ventries/Vbytes/Voffset/vcomment', substr($bytes, $position + 4));
    $large = "PK\x06\x06".pack('PvvVVP4', $fault === 'size' ? 45 : 44, 45, 45, 0, 0, $end['entries'], $end['entries'], $end['bytes'], $end['offset']);
    $locator = "PK\x06\x07".pack('VPV', 0, $fault === 'locator' ? $position + 10000 : $position, 1);
    $count = $fault === 'disagree' ? 3 : 65535;
    $footer = "PK\x05\x06".pack('vvvvVVv', $fault === 'split' ? 1 : 0, 0, $count, $count, 4294967295, 4294967295, 0);
    $bytes = substr($bytes, 0, $position).$large.$locator.$footer;
    expect(fn () => $this->archives->importFrom($this->actor, 'other', (string) Str::uuid(), ($this->stream)($bytes), [$id => (string) Str::uuid()], 'bad-zip64'))->toThrow(ValidationException::class);
})->with(['size', 'locator', 'disagree', 'split']);

it('reports private directory read and seek failures without passing partial headers to libzip', function (bool $seekFailure) {
    ChangingAudioStream::$bytes = str_repeat('x', $seekFailure ? 65601 : 22);
    ChangingAudioStream::$mode = $seekFailure ? 'failed-seek' : '';
    stream_wrapper_register('musicziplimits', ChangingAudioStream::class);
    try {
        expect(fn () => app(ZipDirectoryLimits::class)->validate('musicziplimits://directory'))->toThrow(ValidationException::class);
    } finally {
        stream_wrapper_unregister('musicziplimits');
    }
})->with([true, false]);

it('rejects foreign pack identities before ingesting otherwise authorized destination uploads', function () {
    $id = ($this->publish)();
    ($this->save)([$id]);
    $source = ($this->export)();
    expect(fn () => $this->archives->importFrom($this->actor, 'other', $this->packId, $source, [$id => (string) Str::uuid()], 'foreign-pack'))->toThrow(RecordNotFoundException::class);
    expect(DB::table('music_media_uploads')->count())->toBe(1);
});

it('rejects an over budget archive plan before accepting destination declarations or audio', function () {
    $ids = [($this->publish)(), ($this->publish)(sample: 1)];
    ($this->save)($ids);
    $source = ($this->export)();
    config()->set('music-media.max_reserved_bytes', 96);
    $map = array_combine($ids, [(string) Str::uuid(), (string) Str::uuid()]);
    expect(fn () => $this->archives->importFrom($this->actor, 'other', (string) Str::uuid(), $source, $map, 'budget-plan'))->toThrow(UploadBudgetExceeded::class);
    expect(DB::table('music_media_uploads')->where('workspace_id', 'other')->count())->toBe(0)
        ->and(DB::table('music_media_workspace_usage')->where('workspace_id', 'other')->count())->toBe(0)
        ->and(DB::table('music_media_packs')->count())->toBe(1);
});
