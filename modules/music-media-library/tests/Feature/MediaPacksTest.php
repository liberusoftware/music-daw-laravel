<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\QueryException;
use Illuminate\Database\RecordNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Liberu\Foundation\Files\Contracts\MalwareScanner;
use Liberu\Foundation\ImportExport\Contracts\TransferAuthorizer;
use Liberu\Music\MediaLibrary\Commands\SaveMediaPack;
use Liberu\Music\MediaLibrary\Commands\StartUpload;
use Liberu\Music\MediaLibrary\Data\MediaPack;
use Liberu\Music\MediaLibrary\Events\MediaPackSaved;
use Liberu\Music\MediaLibrary\Exceptions\InvalidAudio;
use Liberu\Music\MediaLibrary\Exceptions\MetadataCommandConflict;
use Liberu\Music\MediaLibrary\Exceptions\MetadataConflict;
use Liberu\Music\MediaLibrary\Services\MediaPacks;
use Liberu\Music\MediaLibrary\Services\MediaRetention;
use Liberu\Music\MediaLibrary\Services\MediaUploads;

beforeEach(function () {
    $this->artisan('migrate', ['--path' => realpath(__DIR__.'/../../database/migrations'), '--realpath' => true])->assertSuccessful();
    config()->set('music-media.disk', 'media-packs-test');
    $this->disk = Storage::fake('media-packs-test');
    $this->actor = new GenericUser(['id' => 1]);
    foreach (['upload', 'read', 'retain', 'pack_write', 'pack_read', 'pack_export', 'pack_import', 'distribute'] as $operation) {
        app(Gate::class)->define('music.media.'.$operation, fn ($actor, $workspace) => $actor->id === 1 && in_array($workspace, ['studio', 'other']));
    }
    app(Gate::class)->define('music.media.pack_distribute', fn ($actor, MediaPack $pack) => $actor->id === 1 && in_array($pack->workspaceId, ['studio', 'other']));
    $scanner = Mockery::mock(MalwareScanner::class);
    $scanner->shouldReceive('clean')->andReturn(true);
    app()->instance(MalwareScanner::class, $scanner);
    $this->transfers = Mockery::mock(TransferAuthorizer::class);
    $this->transfers->shouldReceive('allowed')->with('1', 'music-media-pack', Mockery::any())->andReturn(true)->byDefault();
    app()->instance(TransferAuthorizer::class, $this->transfers);
    $this->packs = app(MediaPacks::class);
    $this->uploads = app(MediaUploads::class);
    $this->packId = (string) Str::uuid();
    $this->rights = ['source' => 'Original recordings', 'license' => 'CC-BY-4.0', 'attribution' => 'Studio artist'];
    $this->publish = function (string $workspace = 'studio', ?array $provenance = null, bool $complete = true, int $sample = 16384): string {
        $format = pack('vvVVvv', 1, 1, 48000, 96000, 2, 16);
        $data = 'fmt '.pack('V', 16).$format.'data'.pack('V', 4).pack('v*', 0, $sample);
        $bytes = 'RIFF'.pack('V', strlen($data) + 4).'WAVE'.$data;
        $id = (string) Str::uuid();
        $this->uploads->start($this->actor, new StartUpload($workspace, $id, 'Piano take', strlen($bytes), hash('sha256', $bytes), $provenance ?? $this->rights));
        $this->uploads->append($this->actor, $workspace, $id, 0, $bytes);
        if ($complete) {
            $this->uploads->complete($this->actor, $workspace, $id);
        }

        return $id;
    };
    $this->command = fn (array $ids, int $version = 0, string $key = 'create'): SaveMediaPack => new SaveMediaPack('studio', $this->packId, 'Studio collection', $ids, $this->rights, $version, $key);
    $this->events = [];
    app(Dispatcher::class)->listen(MediaPackSaved::class, function ($event) {
        $this->events[] = $event;
    });
});

it('saves canonical versioned packs with immutable provenance and atomic revision retention', function () {
    $first = ($this->publish)();
    $second = ($this->publish)(sample: 1);
    $pack = $this->packs->save($this->actor, ($this->command)([$second, $first, $second]));
    $ids = [$first, $second];
    sort($ids, SORT_STRING);
    expect(array_column(array_column($pack->assets, 'reference'), 'id'))->toBe($ids)
        ->and($pack->version)->toBe(1)->and($pack->rights)->toBe($this->rights)
        ->and($pack->assets[0]['provenance']['license'])->toBe('CC-BY-4.0')
        ->and($this->events)->toHaveCount(1)->and($this->events[0]->actorId)->toBe('1')
        ->and($this->events[0]->pack)->toBe($pack)
        ->and($this->packs->get($this->actor, 'studio', $this->packId)->jsonSerialize())->toBe($pack->jsonSerialize());
    $next = $this->packs->save($this->actor, ($this->command)([$second], 1, 'second'));
    expect($next->version)->toBe(2)->and($this->packs->get($this->actor, 'studio', $this->packId, 1)->jsonSerialize())->toBe($pack->jsonSerialize())
        ->and($this->packs->get($this->actor, 'studio', $this->packId)->version)->toBe(2)
        ->and(DB::table('music_media_retention')->count())->toBe(3)
        ->and(DB::table('music_media_retention')->where('reference_kind', 'pack')->whereNull('expires_at')->count())->toBe(3)
        ->and(app(MediaRetention::class)->isProtected($this->actor, 'studio', $first))->toBeTrue();
    $history = $this->packs->history($this->actor, 'studio', $this->packId, 1);
    expect($history)->toHaveCount(1)->and($history[0]->jsonSerialize())->toBe([
        'schema_version' => 1, 'version' => 2, 'name' => 'Studio collection', 'asset_count' => 1,
        'created_at' => DB::table('music_media_pack_revisions')->where('version', 2)->value('created_at'),
    ])->and($this->packs->history($this->actor, 'studio', $this->packId, before: 2)[0]->version)->toBe(1);
    expect(json_encode($pack))->not->toContain('blob_key', 'music/assets/', 'actor_id');
});

it('replays the exact revision without overwriting newer membership or emitting another event', function () {
    $first = ($this->publish)();
    $second = ($this->publish)(sample: 1);
    $command = ($this->command)([$first]);
    $original = $this->packs->save($this->actor, $command);
    $this->packs->save($this->actor, ($this->command)([$second], 1, 'replace'));
    DB::table('music_media_retention')->where('asset_id', $first)->delete();
    expect($this->packs->save($this->actor, $command)->jsonSerialize())->toBe($original->jsonSerialize())
        ->and(DB::table('music_media_pack_revisions')->count())->toBe(2)->and($this->events)->toHaveCount(2)
        ->and(DB::table('music_media_retention')->count())->toBe(2)
        ->and($this->packs->get($this->actor, 'studio', $this->packId)->assets[0]['reference']['id'])->toBe($second);
    expect(fn () => $this->packs->save($this->actor, ($this->command)([$second])))->toThrow(MetadataCommandConflict::class, 'command key');
    expect(fn () => $this->packs->save($this->actor, ($this->command)([$first], 1, 'stale')))->toThrow(MetadataConflict::class, 'changed');
    app(Gate::class)->define('music.media.read', fn () => false);
    expect(fn () => $this->packs->save($this->actor, $command))->toThrow(AuthorizationException::class);
});

it('exports authorized revisions with original provenance and imports into verified destination identities', function () {
    $id = ($this->publish)();
    $saved = $this->packs->save($this->actor, ($this->command)([$id]));
    $manifest = $this->packs->export($this->actor, 'studio', $this->packId, 1);
    $input = json_decode($manifest, true, flags: JSON_THROW_ON_ERROR);
    expect($input['format'])->toBe('liberu.music.media-pack')->and($input['format_version'])->toBe(1)
        ->and($input['document'])->toBe($saved->jsonSerialize())
        ->and($input['checksum'])->toBe(hash('sha256', json_encode($saved, JSON_THROW_ON_ERROR)));
    $destination = ($this->publish)('other');
    $newPack = (string) Str::uuid();
    $imported = $this->packs->import($this->actor, 'other', $newPack, $manifest, [$id => $destination], 'import');
    expect($imported->version)->toBe(1)->and($imported->workspaceId)->toBe('other')->and($imported->packId)->toBe($newPack)
        ->and($imported->rights)->toBe($saved->rights)->and($imported->name)->toBe($saved->name)
        ->and($imported->assets[0]['reference']['id'])->toBe($destination)
        ->and($imported->assets[0]['reference']['sha256'])->toBe($saved->assets[0]['reference']['sha256'])
        ->and($imported->assets[0]['provenance'])->toBe($saved->assets[0]['provenance']);
    expect($this->packs->import($this->actor, 'other', $newPack, $manifest, [$id => $destination], 'import')->jsonSerialize())->toBe($imported->jsonSerialize())
        ->and(DB::table('music_media_pack_revisions')->count())->toBe(2)->and($this->events)->toHaveCount(2);
});

it('requires both the Music and foundation transfer policies and rechecks per asset distribution rights', function () {
    $id = ($this->publish)();
    $this->packs->save($this->actor, ($this->command)([$id]));
    $manifest = $this->packs->export($this->actor, 'studio', $this->packId);
    app(Gate::class)->define('music.media.distribute', fn () => false);
    expect(fn () => $this->packs->export($this->actor, 'studio', $this->packId))->toThrow(AuthorizationException::class);
    app()->forgetInstance(TransferAuthorizer::class);
    $without = app(MediaPacks::class);
    expect(fn () => $without->export($this->actor, 'studio', $this->packId))->toThrow(AuthorizationException::class, 'foundation');
    expect(fn () => $without->import($this->actor, 'studio', (string) Str::uuid(), $manifest, [$id => $id], 'blocked'))->toThrow(AuthorizationException::class, 'foundation');
    $this->transfers->shouldReceive('allowed')->andReturn(false);
    expect(fn () => $this->packs->import($this->actor, 'studio', (string) Str::uuid(), $manifest, [$id => $id], 'denied'))->toThrow(AuthorizationException::class, 'foundation');
    expect(fn () => $this->packs->export($this->actor, 'studio', $this->packId))->toThrow(AuthorizationException::class, 'foundation');
});

it('allows internal collections with incomplete asset provenance but refuses their distribution', function () {
    $id = ($this->publish)(provenance: []);
    $this->packs->save($this->actor, ($this->command)([$id]));
    expect(fn () => $this->packs->export($this->actor, 'studio', $this->packId))->toThrow(ValidationException::class);
});

it('authorizes pack actions and referenced assets without granting access through workspace or checksum knowledge', function () {
    $id = ($this->publish)();
    $command = ($this->command)([$id]);
    $this->packs->save($this->actor, $command);
    $other = new GenericUser(['id' => 2]);
    foreach ([
        fn () => $this->packs->save($other, $command),
        fn () => $this->packs->get($other, 'studio', $this->packId),
        fn () => $this->packs->history($other, 'studio', $this->packId),
        fn () => $this->packs->export($other, 'studio', $this->packId),
        fn () => $this->packs->import($other, 'studio', $this->packId, '{}', [], 'denied'),
        fn () => $this->packs->get($this->actor, 'wrong', $this->packId),
    ] as $action) {
        expect($action)->toThrow(AuthorizationException::class);
    }
    expect(fn () => $this->packs->get($this->actor, 'other', $this->packId))->toThrow(RecordNotFoundException::class);
    expect(fn () => $this->packs->save($this->actor, new SaveMediaPack('other', $this->packId, 'Collision', [$id], $this->rights, 0, 'collision')))->toThrow(RecordNotFoundException::class);
    expect(fn () => $this->packs->save($this->actor, new SaveMediaPack('studio', (string) Str::uuid(), 'Missing', [$id], $this->rights, 1, 'missing')))->toThrow(RecordNotFoundException::class);
    app(Gate::class)->define('music.media.read', fn () => false);
    expect(fn () => $this->packs->get($this->actor, 'studio', $this->packId))->toThrow(AuthorizationException::class);
    expect(fn () => $this->packs->save($this->actor, ($this->command)([$id], 1, 'revoked')))->toThrow(AuthorizationException::class);
    expect(DB::table('music_media_pack_revisions')->count())->toBe(1);
});

it('refuses incomplete missing and wrong workspace assets without retaining a pack', function () {
    $pending = ($this->publish)(complete: false);
    $foreign = ($this->publish)('other');
    foreach ([$pending, $foreign, (string) Str::uuid()] as $id) {
        expect(fn () => $this->packs->save($this->actor, ($this->command)([$id])))->toThrow(RecordNotFoundException::class);
    }
    $id = ($this->publish)();
    $this->disk->delete(DB::table('music_media_uploads')->where('id', $id)->value('blob_key'));
    expect(fn () => $this->packs->save($this->actor, ($this->command)([$id])))->toThrow(InvalidAudio::class, 'missing');
    expect(DB::table('music_media_packs')->count())->toBe(0)->and(DB::table('music_media_retention')->count())->toBe(0);
});

it('rejects mismatched destination bytes or provenance and requires an exact import map', function () {
    $id = ($this->publish)();
    $this->packs->save($this->actor, ($this->command)([$id]));
    $manifest = $this->packs->export($this->actor, 'studio', $this->packId);
    $wrongAudio = ($this->publish)(sample: 1);
    $wrongRights = ($this->publish)(provenance: ['license' => 'Other terms']);
    foreach ([$wrongAudio, $wrongRights] as $destination) {
        expect(fn () => $this->packs->import($this->actor, 'studio', (string) Str::uuid(), $manifest, [$id => $destination], 'mismatch'))->toThrow(ValidationException::class, 'provenance');
    }
    foreach ([[], [(string) Str::uuid() => $id], [$id => $id, (string) Str::uuid() => (string) Str::uuid()]] as $map) {
        expect(fn () => $this->packs->import($this->actor, 'studio', (string) Str::uuid(), $manifest, $map, 'bad-map'))->toThrow(ValidationException::class);
    }
    expect(fn () => $this->packs->import($this->actor, 'other', (string) Str::uuid(), $manifest, [$id => $id], 'cross'))->toThrow(RecordNotFoundException::class);
    expect(DB::table('music_media_packs')->count())->toBe(1);
});

it('validates manifest format checksum schemas and size before creating an imported pack', function () {
    $id = ($this->publish)();
    $this->packs->save($this->actor, ($this->command)([$id]));
    $input = json_decode($this->packs->export($this->actor, 'studio', $this->packId), true);
    foreach ([['format' => 'wrong'], ['format_version' => 2], ['checksum' => str_repeat('0', 64)], ['document' => []], ['extra' => 'unsupported']] as $change) {
        expect(fn () => $this->packs->import($this->actor, 'studio', (string) Str::uuid(), json_encode(array_replace($input, $change)), [$id => $id], 'invalid'))->toThrow(ValidationException::class);
    }
    $input['document']['schema_version'] = 2;
    $input['checksum'] = hash('sha256', json_encode($input['document']));
    expect(fn () => $this->packs->import($this->actor, 'studio', (string) Str::uuid(), json_encode($input), [$id => $id], 'schema'))->toThrow(ValidationException::class);
    expect(fn () => $this->packs->import($this->actor, 'studio', (string) Str::uuid(), 'null', [$id => $id], 'null'))->toThrow(ValidationException::class);
    expect(fn () => $this->packs->import($this->actor, 'studio', (string) Str::uuid(), '{', [$id => $id], 'json'))->toThrow(JsonException::class);
    expect(fn () => $this->packs->import($this->actor, 'studio', (string) Str::uuid(), str_repeat('x', 8388609), [$id => $id], 'oversize'))->toThrow(ValidationException::class, 'size');
});

it('checks stored snapshot integrity identity and verified metadata before exposing a revision', function () {
    $id = ($this->publish)();
    $this->packs->save($this->actor, ($this->command)([$id]));
    $row = DB::table('music_media_pack_revisions')->first();
    DB::table('music_media_pack_revisions')->update(['checksum' => str_repeat('0', 64)]);
    expect(fn () => $this->packs->get($this->actor, 'studio', $this->packId))->toThrow(ValidationException::class, 'integrity');
    DB::table('music_media_pack_revisions')->update(['document' => 'null', 'checksum' => hash('sha256', 'null')]);
    expect(fn () => $this->packs->get($this->actor, 'studio', $this->packId))->toThrow(ValidationException::class, 'object');
    $input = json_decode($row->document, true);
    $input['workspace_id'] = 'other';
    $document = json_encode($input);
    DB::table('music_media_pack_revisions')->update(['document' => $document, 'checksum' => hash('sha256', $document)]);
    expect(fn () => $this->packs->get($this->actor, 'studio', $this->packId))->toThrow(ValidationException::class, 'identity');
    DB::table('music_media_pack_revisions')->update(['document' => $row->document, 'checksum' => $row->checksum]);
    DB::table('music_media_uploads')->where('id', $id)->update(['provenance' => json_encode(['license' => 'Replaced'])]);
    expect(fn () => $this->packs->get($this->actor, 'studio', $this->packId))->toThrow(ValidationException::class, 'provenance');
});

it('rolls back retention revisions and events when a pack cannot commit', function () {
    $id = ($this->publish)();
    DB::unprepared("CREATE TRIGGER refuse_pack_revision BEFORE INSERT ON music_media_pack_revisions BEGIN SELECT RAISE(ABORT, 'pack failed'); END");
    expect(fn () => $this->packs->save($this->actor, ($this->command)([$id])))->toThrow(QueryException::class);
    expect(DB::table('music_media_packs')->count())->toBe(0)->and(DB::table('music_media_retention')->count())->toBe(0)->and($this->events)->toBe([]);
    DB::unprepared('DROP TRIGGER refuse_pack_revision');
    DB::beginTransaction();
    $this->packs->save($this->actor, ($this->command)([$id]));
    expect($this->events)->toBe([]);
    DB::rollBack();
    expect(DB::table('music_media_pack_revisions')->count())->toBe(0)->and(DB::table('music_media_retention')->count())->toBe(0)->and($this->events)->toBe([]);
    DB::unprepared('CREATE TRIGGER refuse_pack_write BEFORE UPDATE ON music_media_packs BEGIN SELECT RAISE(IGNORE); END');
    expect(fn () => $this->packs->save($this->actor, ($this->command)([$id])))->toThrow(MetadataConflict::class, 'during');
});

it('bounds serialized snapshot size even when individually valid assets have large provenance', function () {
    $id = ($this->publish)(provenance: array_fill_keys(['source', 'license', 'attribution'], str_repeat('é', 2048)));
    $row = (array) DB::table('music_media_uploads')->where('id', $id)->first();
    $rows = [];
    $ids = [];
    for ($index = 0; $index < 130; $index++) {
        $ids[] = (string) Str::uuid();
        $rows[] = array_replace($row, ['id' => $ids[$index]]);
    }
    DB::table('music_media_uploads')->insert($rows);
    expect(fn () => $this->packs->save($this->actor, ($this->command)($ids)))->toThrow(ValidationException::class, 'bounded');
    expect(DB::table('music_media_retention')->count())->toBe(0)->and(DB::table('music_media_packs')->count())->toBe(0);
});

it('keeps owned asset resolution and history queries bounded as membership grows', function () {
    $ids = [($this->publish)(), ($this->publish)(sample: 1), ($this->publish)(sample: 2)];
    $this->packs->save($this->actor, ($this->command)($ids));
    DB::enableQueryLog();
    DB::flushQueryLog();
    $this->packs->get($this->actor, 'studio', $this->packId);
    expect(DB::getQueryLog())->toHaveCount(2);
    DB::flushQueryLog();
    $this->packs->history($this->actor, 'studio', $this->packId);
    expect(DB::getQueryLog())->toHaveCount(1)->and(DB::getQueryLog()[0]['query'])->not->toContain('document');
    DB::disableQueryLog();
});

it('reverses pack schema without deleting recordings or durable retention references', function () {
    $id = ($this->publish)();
    $this->packs->save($this->actor, ($this->command)([$id]));
    $migration = require __DIR__.'/../../database/migrations/2026_11_05_194359_create_music_media_pack_tables.php';
    $migration->down();
    expect(Schema::hasTable('music_media_packs'))->toBeFalse()->and(DB::table('music_media_retention')->count())->toBe(1)
        ->and($this->uploads->get($this->actor, 'studio', $id)->id)->toBe($id);
    $migration->up();
    expect($this->packs->history($this->actor, 'studio', $this->packId))->toBe([]);
});

it('validates history and revision bounds', function () {
    foreach ([0, 101] as $limit) {
        expect(fn () => $this->packs->history($this->actor, 'studio', $this->packId, $limit))->toThrow(ValidationException::class);
    }
    expect(fn () => $this->packs->history($this->actor, 'studio', $this->packId, before: 0))->toThrow(ValidationException::class);
    expect(fn () => $this->packs->get($this->actor, 'studio', $this->packId, 0))->toThrow(ValidationException::class);
});

it('validates command identity membership rights and optimistic version bounds', function (string $field, mixed $value) {
    $input = ['workspaceId' => 'studio', 'packId' => $this->packId, 'name' => 'Pack', 'assetIds' => [(string) Str::uuid()], 'rights' => $this->rights, 'expectedVersion' => 0, 'commandKey' => 'save'];
    $input[$field] = $value;
    expect(fn () => new SaveMediaPack(...$input))->toThrow(ValidationException::class);
})->with([
    ['workspaceId', ''], ['packId', 'invalid'], ['name', ''], ['assetIds', []], ['assetIds', ['bad']],
    ['assetIds', array_fill(0, 1001, '00000000-0000-4000-8000-000000000001')],
    ['rights', []], ['rights', ['source' => 'Here', 'license' => 'Terms', 'attribution' => 'Artist', 'extra' => 'ignored']],
    ['expectedVersion', -1], ['expectedVersion', PHP_INT_MAX], ['commandKey', ''],
]);

it('exports portable rights metadata and imports only matching verified destination assets', function () {
    $policy = Mockery::mock(TransferAuthorizer::class);
    $policy->shouldReceive('allowed')->with('1', 'music-media-pack', 'export')->andReturn(true);
    $policy->shouldReceive('allowed')->with('1', 'music-media-pack', 'import')->andReturn(true);
    app()->instance(TransferAuthorizer::class, $policy);
    $packs = app(MediaPacks::class);
    $source = ($this->publish)();
    $packs->save($this->actor, ($this->command)([$source]));
    $manifest = $packs->export($this->actor, 'studio', $this->packId);
    expect($manifest)->not->toContain('blob_key', 'quarantine');
    $destination = ($this->publish)();
    $imported = $packs->import($this->actor, 'studio', (string) Str::uuid(), $manifest, [$source => $destination], 'import');
    expect($imported->assets[0]['reference']['id'])->toBe($destination)
        ->and($imported->rights['license'])->toBe('CC-BY-4.0');
    $different = ($this->publish)(sample: 1);
    expect(fn () => $packs->import($this->actor, 'studio', (string) Str::uuid(), $manifest, [$source => $different], 'mismatch'))->toThrow(ValidationException::class)
        ->and(fn () => $packs->import($this->actor, 'studio', (string) Str::uuid(), $manifest, [$destination => $source], 'map'))->toThrow(ValidationException::class);
    $tampered = json_decode($manifest, true);
    $tampered['document']['name'] = 'Changed';
    expect(fn () => $packs->import($this->actor, 'studio', (string) Str::uuid(), json_encode($tampered), [$source => $destination], 'tamper'))->toThrow(ValidationException::class);
});

it('rejects a checksummed scalar stored in place of a pack document', function () {
    $asset = ($this->publish)();
    $this->packs->save($this->actor, ($this->command)([$asset]));
    DB::table('music_media_pack_revisions')->update(['document' => 'null', 'checksum' => hash('sha256', 'null')]);
    expect(fn () => $this->packs->get($this->actor, 'studio', $this->packId))->toThrow(ValidationException::class);
});

it('normalizes portable object order and maps multiple destination identities without positional assumptions', function () {
    $first = ($this->publish)();
    $second = ($this->publish)(sample: 1);
    $this->packs->save($this->actor, ($this->command)([$first, $second]));
    $input = json_decode($this->packs->export($this->actor, 'studio', $this->packId), true);
    $input['document']['assets'] = array_reverse($input['document']['assets']);
    foreach ($input['document']['assets'] as &$asset) {
        $asset['reference'] = array_reverse($asset['reference'], true);
        $asset['provenance'] = array_reverse($asset['provenance'], true);
    }
    unset($asset);
    $input['checksum'] = hash('sha256', json_encode($input['document']));
    $destinationFirst = ($this->publish)('other');
    $destinationSecond = ($this->publish)('other', sample: 1);
    $imported = $this->packs->import($this->actor, 'other', (string) Str::uuid(), json_encode($input), [$second => $destinationSecond, $first => $destinationFirst], 'import-multiple');
    $ids = [$destinationFirst, $destinationSecond];
    sort($ids, SORT_STRING);
    expect(array_column(array_column($imported->assets, 'reference'), 'id'))->toBe($ids);
});

it('applies distribution review to the exact requested immutable revision rather than the latest pack', function () {
    $id = ($this->publish)();
    $this->packs->save($this->actor, ($this->command)([$id]));
    $this->packs->save($this->actor, new SaveMediaPack('studio', $this->packId, 'Revised terms', [$id], array_replace($this->rights, ['license' => 'Unreviewed terms']), 1, 'terms'));
    $reviewed = [];
    app(Gate::class)->define('music.media.pack_distribute', function ($actor, MediaPack $pack) use (&$reviewed) {
        $reviewed[] = $pack;

        return $pack->version === 1 && $pack->rights['license'] === 'CC-BY-4.0';
    });
    expect(fn () => $this->packs->export($this->actor, 'studio', $this->packId))->toThrow(AuthorizationException::class);
    $manifest = json_decode($this->packs->export($this->actor, 'studio', $this->packId, 1), true);
    expect($manifest['document']['version'])->toBe(1)->and($reviewed[0]->version)->toBe(2)->and($reviewed[1]->version)->toBe(1)
        ->and($reviewed[1]->assets[0]['provenance']['license'])->toBe('CC-BY-4.0');
});

it('fails closed when the host distribution policy denies approval', function () {
    $id = ($this->publish)();
    $this->packs->save($this->actor, ($this->command)([$id]));
    app(Gate::class)->define('music.media.pack_distribute', fn () => false);
    expect(fn () => $this->packs->export($this->actor, 'studio', $this->packId))->toThrow(AuthorizationException::class);
});
