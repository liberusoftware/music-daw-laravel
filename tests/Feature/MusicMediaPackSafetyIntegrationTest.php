<?php

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\RecordNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Liberu\Foundation\Files\Contracts\MalwareScanner;
use Liberu\Foundation\ImportExport\Contracts\TransferAuthorizer;
use Liberu\Music\MediaLibrary\Commands\SaveMediaPack;
use Liberu\Music\MediaLibrary\Commands\StartUpload;
use Liberu\Music\MediaLibrary\Exceptions\InvalidAudio;
use Liberu\Music\MediaLibrary\Exceptions\MetadataConflict;
use Liberu\Music\MediaLibrary\MusicMediaLibraryServiceProvider;
use Liberu\Music\MediaLibrary\Services\MediaPacks;
use Liberu\Music\MediaLibrary\Services\MediaUploads;

beforeEach(function () {
    $this->app->register(MusicMediaLibraryServiceProvider::class);
    $this->artisan('migrate', ['--path' => base_path('modules/music-media-library/database/migrations'), '--realpath' => true])->assertSuccessful();
    config()->set('music-media.disk', 'host-media-pack-safety');
    $this->disk = Storage::fake('host-media-pack-safety');
    $this->actor = User::factory()->create();
    foreach (['upload', 'read', 'retain', 'pack_write', 'pack_read', 'pack_export', 'pack_import', 'distribute'] as $operation) {
        Gate::define('music.media.'.$operation, fn ($actor, $workspace) => $actor->is($this->actor) && $workspace === 'studio');
    }
    Gate::define('music.media.pack_distribute', fn ($actor, $pack) => $actor->is($this->actor) && $pack->workspaceId === 'studio');
    $scanner = Mockery::mock(MalwareScanner::class);
    $scanner->shouldReceive('clean')->andReturnTrue();
    app()->instance(MalwareScanner::class, $scanner);
    $this->transfers = Mockery::mock(TransferAuthorizer::class);
    $this->transfers->shouldReceive('allowed')->with((string) $this->actor->id, 'music-media-pack', Mockery::any())->andReturnTrue()->byDefault();
    app()->instance(TransferAuthorizer::class, $this->transfers);
    $this->rights = ['source' => 'Studio recording', 'license' => 'CC-BY-4.0', 'attribution' => 'Studio musician'];
    $this->publish = function (int $sample = 0, ?array $provenance = null): string {
        $format = pack('vvVVvv', 1, 1, 48000, 96000, 2, 16);
        $chunks = 'fmt '.pack('V', 16).$format.'data'.pack('V', 4).pack('v*', $sample, 16384);
        $bytes = 'RIFF'.pack('V', strlen($chunks) + 4).'WAVE'.$chunks;
        $id = (string) Str::uuid();
        $uploads = app(MediaUploads::class);
        $uploads->start($this->actor, new StartUpload('studio', $id, 'Take.wav', strlen($bytes), hash('sha256', $bytes), $provenance ?? $this->rights));
        $uploads->append($this->actor, 'studio', $id, 0, $bytes);
        $uploads->complete($this->actor, 'studio', $id);

        return $id;
    };
    $this->assetId = ($this->publish)();
    $this->packId = (string) Str::uuid();
    $this->packs = app(MediaPacks::class);
    $this->command = new SaveMediaPack('studio', $this->packId, 'Collection', [$this->assetId], $this->rights, 0, 'create');
});

it('replays immutable pack commands and preserves every revisions retained assets', function () {
    $first = $this->packs->save($this->actor, $this->command);
    $secondId = ($this->publish)(1);
    $second = $this->packs->save($this->actor, new SaveMediaPack('studio', $this->packId, 'Updated', [$secondId], $this->rights, 1, 'update'));
    expect($this->packs->save($this->actor, $this->command)->jsonSerialize())->toBe($first->jsonSerialize())
        ->and($this->packs->get($this->actor, 'studio', $this->packId, 1)->jsonSerialize())->toBe($first->jsonSerialize())
        ->and($this->packs->get($this->actor, 'studio', $this->packId)->jsonSerialize())->toBe($second->jsonSerialize())
        ->and(DB::table('music_media_retention')->where('reference_kind', 'pack')->count())->toBe(2);
    $history = $this->packs->history($this->actor, 'studio', $this->packId, limit: 1);
    expect($history)->toHaveCount(1)->and($history[0]->jsonSerialize())->toMatchArray(['schema_version' => 1, 'version' => 2, 'name' => 'Updated', 'asset_count' => 1]);
    expect($this->packs->history($this->actor, 'studio', $this->packId, before: 2)[0]->version)->toBe(1);
    expect(fn () => $this->packs->save($this->actor, new SaveMediaPack('studio', $this->packId, 'Changed replay', [$this->assetId], $this->rights, 0, 'create')))->toThrow(MetadataConflict::class, 'command key')
        ->and(fn () => $this->packs->save($this->actor, new SaveMediaPack('studio', $this->packId, 'Stale', [$this->assetId], $this->rights, 1, 'stale')))->toThrow(MetadataConflict::class, 'reload');
});

it('checks foundation transfer permission separately from workspace and pack distribution policies', function () {
    $this->packs->save($this->actor, $this->command);
    Gate::define('music.media.pack_distribute', fn () => false);
    expect(fn () => $this->packs->export($this->actor, 'studio', $this->packId))->toThrow(AuthorizationException::class);
    Gate::define('music.media.pack_distribute', fn () => true);
    Gate::define('music.media.distribute', fn () => false);
    expect(fn () => $this->packs->export($this->actor, 'studio', $this->packId))->toThrow(AuthorizationException::class);
    Gate::define('music.media.distribute', fn () => true);
    $this->transfers->shouldReceive('allowed')->with((string) $this->actor->id, 'music-media-pack', 'export')->andReturnFalse();
    expect(fn () => $this->packs->export($this->actor, 'studio', $this->packId))->toThrow(AuthorizationException::class, 'foundation transfer');
    expect(fn () => $this->packs->get(User::factory()->create(), 'studio', $this->packId))->toThrow(AuthorizationException::class);
    Gate::define('music.media.pack_read', fn () => true);
    expect(fn () => $this->packs->get($this->actor, 'other', $this->packId))->toThrow(RecordNotFoundException::class);
});

it('rejects unsafe portable manifests and wrong import identity maps before creating packs', function ($defect) {
    $this->packs->save($this->actor, $this->command);
    $manifest = $this->packs->export($this->actor, 'studio', $this->packId);
    $destination = (string) Str::uuid();
    $map = [$this->assetId => $this->assetId];
    if ($defect === 'checksum') {
        $input = json_decode($manifest, true, flags: JSON_THROW_ON_ERROR);
        $input['checksum'] = str_repeat('0', 64);
        $manifest = json_encode($input, JSON_THROW_ON_ERROR);
    } elseif ($defect === 'map') {
        $map = [(string) Str::uuid() => $this->assetId];
    } else {
        $manifest = str_repeat(' ', 8 * 1024 * 1024 + 1);
    }
    expect(fn () => $this->packs->previewImport($this->actor, 'studio', $destination, $manifest, $map))->toThrow(ValidationException::class);
    expect(DB::table('music_media_packs')->where('id', $destination)->exists())->toBeFalse()
        ->and(DB::table('music_media_pack_revisions')->count())->toBe(1);
})->with(['checksum', 'map', 'oversize']);

it('rejects altered stored pack documents and identities before exposing asset references', function ($defect) {
    $this->packs->save($this->actor, $this->command);
    $row = DB::table('music_media_pack_revisions')->where('pack_id', $this->packId)->first();
    if ($defect === 'checksum') {
        $changes = ['checksum' => str_repeat('0', 64)];
    } else {
        $input = json_decode($row->document, true, flags: JSON_THROW_ON_ERROR);
        if ($defect === 'identity') {
            $input['pack_id'] = (string) Str::uuid();
        } else {
            $input = 'invalid-object';
        }
        $document = json_encode($input, JSON_THROW_ON_ERROR);
        $changes = ['document' => $document, 'checksum' => hash('sha256', $document)];
    }
    DB::table('music_media_pack_revisions')->where('id', $row->id)->update($changes);
    expect(fn () => $this->packs->get($this->actor, 'studio', $this->packId))->toThrow(ValidationException::class);
})->with(['checksum', 'identity', 'scalar']);

it('rechecks live source provenance availability and ready state for historical snapshots', function ($defect) {
    $this->packs->save($this->actor, $this->command);
    if ($defect === 'missing') {
        $this->disk->delete(DB::table('music_media_uploads')->where('id', $this->assetId)->value('blob_key'));
    } elseif ($defect === 'unready') {
        DB::table('music_media_uploads')->where('id', $this->assetId)->update(['status' => 'cancelled']);
    } else {
        DB::table('music_media_uploads')->where('id', $this->assetId)->update(['provenance' => json_encode($this->rights + ['extra' => 'altered'], JSON_THROW_ON_ERROR)]);
    }
    expect(fn () => $this->packs->get($this->actor, 'studio', $this->packId, 1))
        ->toThrow(match ($defect) {
            'missing' => InvalidAudio::class, 'unready' => RecordNotFoundException::class, default => ValidationException::class
        });
})->with(['missing', 'unready', 'provenance']);

it('rolls back new retention when a pack version update is suppressed', function () {
    $this->packs->save($this->actor, $this->command);
    DB::unprepared('CREATE TRIGGER ignore_host_pack_version BEFORE UPDATE OF version ON music_media_packs BEGIN SELECT RAISE(IGNORE); END');
    try {
        expect(fn () => $this->packs->save($this->actor, new SaveMediaPack('studio', $this->packId, 'Suppressed', [$this->assetId], $this->rights, 1, 'suppressed')))
            ->toThrow(MetadataConflict::class, 'during this save');
        expect(DB::table('music_media_pack_revisions')->count())->toBe(1)
            ->and(DB::table('music_media_retention')->where('reference_kind', 'pack')->count())->toBe(1);
    } finally {
        DB::unprepared('DROP TRIGGER ignore_host_pack_version');
    }
});

it('bounds large pack manifests and rolls back their new identity and retention', function () {
    // Seed an existing catalogue of ready aliases sharing one verified object.
    $source = (array) DB::table('music_media_uploads')->where('id', $this->assetId)->first();
    $source['provenance'] = json_encode(['source' => str_repeat('s', 2048), 'license' => str_repeat('l', 2048), 'attribution' => str_repeat('a', 2048)], JSON_THROW_ON_ERROR);
    $rows = [];
    $ids = [];
    for ($index = 0; $index < 700; $index++) {
        $id = (string) Str::uuid();
        $rows[] = array_replace($source, ['id' => $id, 'duplicate_of' => $this->assetId]);
        $ids[] = $id;
    }
    foreach (array_chunk($rows, 50) as $batch) {
        DB::table('music_media_uploads')->insert($batch);
    }
    expect(fn () => $this->packs->save($this->actor, new SaveMediaPack('studio', $this->packId, 'Large collection', $ids, $this->rights, 0, 'oversized')))
        ->toThrow(ValidationException::class, 'bounded manifest size');
    expect(DB::table('music_media_packs')->where('id', $this->packId)->exists())->toBeFalse()
        ->and(DB::table('music_media_pack_revisions')->count())->toBe(0)
        ->and(DB::table('music_media_retention')->where('reference_kind', 'pack')->count())->toBe(0);
});
