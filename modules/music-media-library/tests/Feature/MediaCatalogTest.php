<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\RecordNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Liberu\Foundation\Files\Contracts\MalwareScanner;
use Liberu\Foundation\Search\Registry\SearcherRegistry;
use Liberu\Foundation\Search\SearchServiceProvider;
use Liberu\Foundation\Search\Services\SearchService;
use Liberu\Music\MediaLibrary\Commands\BrowseAssets;
use Liberu\Music\MediaLibrary\Commands\SearchAssets;
use Liberu\Music\MediaLibrary\Commands\StartUpload;
use Liberu\Music\MediaLibrary\Commands\UpdateAssetMetadata;
use Liberu\Music\MediaLibrary\Events\AssetMetadataUpdated;
use Liberu\Music\MediaLibrary\Exceptions\MetadataCommandConflict;
use Liberu\Music\MediaLibrary\Exceptions\MetadataConflict;
use Liberu\Music\MediaLibrary\MusicMediaLibraryServiceProvider;
use Liberu\Music\MediaLibrary\Services\MediaCatalog;
use Liberu\Music\MediaLibrary\Services\MediaSearchAdapter;
use Liberu\Music\MediaLibrary\Services\MediaUploads;

beforeEach(function () {
    $this->artisan('migrate', ['--path' => realpath(__DIR__.'/../../database/migrations'), '--realpath' => true])->assertSuccessful();
    config()->set('music-media.disk', 'catalog-test');
    $this->disk = Storage::fake('catalog-test');
    $this->actor = new GenericUser(['id' => 1]);
    foreach (['upload', 'read', 'list', 'annotate'] as $operation) {
        app(Gate::class)->define('music.media.'.$operation, fn ($actor, $workspace) => $actor->id === 1 && in_array($workspace, ['studio', 'other']));
    }
    $scanner = Mockery::mock(MalwareScanner::class);
    $scanner->shouldReceive('clean')->andReturn(true);
    app()->instance(MalwareScanner::class, $scanner);
    $format = pack('vvVVvv', 1, 1, 48000, 96000, 2, 16);
    $chunks = 'fmt '.pack('V', 16).$format.'data'.pack('V', 4).pack('v*', 0, 16384);
    $this->bytes = 'RIFF'.pack('V', strlen($chunks) + 4).'WAVE'.$chunks;
    $this->uploads = app(MediaUploads::class);
    $this->catalog = app(MediaCatalog::class);
    $this->publish = function (string $name = 'Original take', string $workspace = 'studio') {
        $id = (string) Str::uuid();
        $this->uploads->start($this->actor, new StartUpload($workspace, $id, $name, strlen($this->bytes), hash('sha256', $this->bytes)));
        $this->uploads->append($this->actor, $workspace, $id, 0, $this->bytes);

        return $this->uploads->complete($this->actor, $workspace, $id);
    };
    $this->events = [];
    app(Dispatcher::class)->listen(AssetMetadataUpdated::class, function ($event) {
        $this->events[] = $event;
    });
});

it('renames and tags an asset without changing verified audio or import provenance', function () {
    $asset = ($this->publish)();
    $command = new UpdateAssetMetadata('studio', $asset->id, 'Kick 01', [' DRUMS ', 'Kick', 'kick'], 0, 'edit');
    $result = $this->catalog->update($this->actor, $command);
    expect($result->jsonSerialize())->toBe(['asset_id' => $asset->id, 'name' => 'Kick 01', 'tags' => ['drums', 'kick'], 'version' => 1])
        ->and($this->events)->toHaveCount(1)->and($this->events[0]->actorId)->toBe('1')
        ->and($this->events[0]->workspaceId)->toBe('studio')->and($this->events[0]->metadata)->toBe($result);
    $page = $this->catalog->search($this->actor, new SearchAssets('studio'));
    $item = $page->items()[0];
    expect($item->name)->toBe('Kick 01')->and($item->tags)->toBe(['drums', 'kick'])->and($item->version)->toBe(1)
        ->and($item->audio['sha256'])->toBe($asset->metadata['sha256'])->and($item->available)->toBeTrue()
        ->and($item->jsonSerialize())->not->toHaveKey('blob_key')
        ->and($this->uploads->get($this->actor, 'studio', $asset->id)->name)->toBe('Original take');
    $this->catalog->update($this->actor, new UpdateAssetMetadata('studio', $asset->id, 'Kick 02', [], 1, 'clear'));
    expect($this->catalog->search($this->actor, new SearchAssets('studio'))->items()[0]->tags)->toBe([])
        ->and(DB::table('music_media_tags')->count())->toBe(0);
});

it('replays a metadata command exactly once even after a later edit', function () {
    $asset = ($this->publish)();
    $firstCommand = new UpdateAssetMetadata('studio', $asset->id, 'First', ['one'], 0, 'first');
    $first = $this->catalog->update($this->actor, $firstCommand);
    $this->catalog->update($this->actor, new UpdateAssetMetadata('studio', $asset->id, 'Second', ['two'], 1, 'second'));
    expect($this->catalog->update($this->actor, $firstCommand)->jsonSerialize())->toBe($first->jsonSerialize())
        ->and($this->events)->toHaveCount(2)
        ->and($this->catalog->search($this->actor, new SearchAssets('studio'))->items()[0]->name)->toBe('Second');
    expect(fn () => $this->catalog->update($this->actor, new UpdateAssetMetadata('studio', $asset->id, 'Changed', [], 0, 'first')))->toThrow(MetadataConflict::class, 'command key');
    expect(fn () => $this->catalog->update($this->actor, new UpdateAssetMetadata('studio', $asset->id, 'Stale', [], 0, 'stale')))->toThrow(MetadataConflict::class, 'reload');
});

it('rolls back rejected concurrent edits and outer transactions without an update event', function () {
    $asset = ($this->publish)();
    $this->catalog->update($this->actor, new UpdateAssetMetadata('studio', $asset->id, 'First', [], 0, 'first'));
    DB::unprepared('CREATE TRIGGER refuse_music_metadata BEFORE UPDATE ON music_media_annotations BEGIN SELECT RAISE(IGNORE); END');
    expect(fn () => $this->catalog->update($this->actor, new UpdateAssetMetadata('studio', $asset->id, 'Concurrent', ['lost'], 1, 'lost')))->toThrow(MetadataConflict::class, 'during');
    DB::unprepared('DROP TRIGGER refuse_music_metadata');
    DB::beginTransaction();
    $this->catalog->update($this->actor, new UpdateAssetMetadata('studio', $asset->id, 'Uncommitted', ['draft'], 1, 'draft'));
    expect($this->events)->toHaveCount(1);
    DB::rollBack();
    expect($this->catalog->search($this->actor, new SearchAssets('studio'))->items()[0]->name)->toBe('First')
        ->and($this->events)->toHaveCount(1)
        ->and(DB::table('music_media_annotation_commands')->count())->toBe(1);
});

it('searches literal Unicode names and all requested normalized tags', function () {
    $first = ($this->publish)('ÉLECTRIC 100%_take!');
    $second = ($this->publish)('electric unrelated');
    $this->catalog->update($this->actor, new UpdateAssetMetadata('studio', $first->id, 'ÉLECTRIC 100%_take!', ['DRUMS', 'résumé'], 0, 'first'));
    $this->catalog->update($this->actor, new UpdateAssetMetadata('studio', $second->id, 'electric unrelated', ['drums', 'resume'], 0, 'second'));
    expect($this->catalog->search($this->actor, new SearchAssets('studio', query: 'électric'))->total())->toBe(1)
        ->and($this->catalog->search($this->actor, new SearchAssets('studio', query: '%_'))->items()[0]->id)->toBe($first->id)
        ->and($this->catalog->search($this->actor, new SearchAssets('studio', query: '!'))->total())->toBe(1)
        ->and($this->catalog->search($this->actor, new SearchAssets('studio', tags: ['DRUMS', 'RÉSUMÉ']))->items()[0]->id)->toBe($first->id)
        ->and($this->catalog->search($this->actor, new SearchAssets('studio', tags: ['resume']))->items()[0]->id)->toBe($second->id)
        ->and($this->catalog->search($this->actor, new SearchAssets('studio', tags: ['missing']))->total())->toBe(0);
});

it('paginates a workspace catalog and finds authorized missing media by checksum', function () {
    $first = ($this->publish)('First');
    ($this->publish)('Second');
    ($this->publish)('Other workspace', 'other');
    $id = (string) Str::uuid();
    $this->uploads->start($this->actor, new StartUpload('studio', $id, 'Receiving', strlen($this->bytes), hash('sha256', $this->bytes)));
    $firstPage = $this->catalog->search($this->actor, new SearchAssets('studio', perPage: 1));
    $next = $this->catalog->search($this->actor, new SearchAssets('studio', page: 2, perPage: 1));
    expect($firstPage->total())->toBe(2)->and($firstPage->items())->toHaveCount(1)
        ->and($next->items())->toHaveCount(1)->and($next->items()[0]->id)->not->toBe($firstPage->items()[0]->id)
        ->and($this->catalog->search($this->actor, new SearchAssets('other'))->total())->toBe(1);
    $key = DB::table('music_media_uploads')->where('id', $first->id)->value('blob_key');
    $this->disk->delete($key);
    $matches = $this->catalog->search($this->actor, new SearchAssets('studio', sha256: hash('sha256', $this->bytes)));
    expect($matches->total())->toBe(2)->and($matches->items()[0]->available)->toBeFalse()
        ->and($this->catalog->search($this->actor, new SearchAssets('studio', sha256: str_repeat('0', 64)))->total())->toBe(0);
});

it('reauthorizes edits, command replay and catalog access and denies cross workspace edits', function () {
    $asset = ($this->publish)();
    $command = new UpdateAssetMetadata('studio', $asset->id, 'First', [], 0, 'first');
    expect(fn () => $this->catalog->update(new GenericUser(['id' => 2]), $command))->toThrow(AuthorizationException::class);
    expect(fn () => $this->catalog->search(new GenericUser(['id' => 2]), new SearchAssets('studio')))->toThrow(AuthorizationException::class);
    expect(fn () => $this->catalog->update($this->actor, new UpdateAssetMetadata('other', $asset->id, 'Wrong scope', [], 0, 'cross')))->toThrow(RecordNotFoundException::class);
    $this->catalog->update($this->actor, $command);
    app(Gate::class)->define('music.media.annotate', fn () => false);
    expect(fn () => $this->catalog->update($this->actor, $command))->toThrow(AuthorizationException::class);
    app(Gate::class)->define('music.media.read', fn () => false);
    expect(fn () => $this->catalog->search($this->actor, new SearchAssets('studio')))->toThrow(AuthorizationException::class);
});

it('loads paginated metadata and tag results with two database queries as the catalog grows', function () {
    for ($index = 0; $index < 8; $index++) {
        $asset = ($this->publish)('Recording '.$index);
        $this->catalog->update($this->actor, new UpdateAssetMetadata('studio', $asset->id, 'Named '.$index, ['drums', 'kick'], 0, 'tag'));
    }
    DB::enableQueryLog();
    DB::flushQueryLog();
    $page = $this->catalog->search($this->actor, new SearchAssets('studio', tags: ['drums', 'kick']));
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    expect($page->total())->toBe(8)->and($queries)->toHaveCount(2);
});

it('rejects invalid catalog and metadata commands', function () {
    $id = (string) Str::uuid();
    foreach ([fn () => new SearchAssets(''), fn () => new SearchAssets('studio', page: 0), fn () => new SearchAssets('studio', perPage: 101), fn () => new SearchAssets('studio', sha256: 'bad'), fn () => new SearchAssets('studio', tags: [' ']), fn () => new UpdateAssetMetadata('studio', $id, '', [], 0, 'key'), fn () => new UpdateAssetMetadata('studio', $id, 'Name', [' '], 0, 'key'), fn () => new UpdateAssetMetadata('studio', $id, 'Name', [], -1, 'key'), fn () => new UpdateAssetMetadata('studio', $id, 'Name', [], 0, '')] as $create) {
        expect($create)->toThrow(ValidationException::class);
    }
});

it('integrates the foundation registry using the authenticated principal and validates HTTP-style filters', function () {
    $asset = ($this->publish)('Searchable take');
    $this->app->register(SearchServiceProvider::class);
    (new MusicMediaLibraryServiceProvider($this->app))->boot();
    expect(app(SearcherRegistry::class)->types())->toContain('music-media');
    expect(fn () => app(SearchService::class)->searchAll(['types' => ['music-media'], 'workspace_id' => 'studio', 'actor' => $this->actor]))->toThrow(AuthenticationException::class);
    $this->actingAs($this->actor);
    $results = app(SearchService::class)->searchAll(['types' => ['music-media'], 'workspace_id' => 'studio', 'query' => 'take', 'page' => '1', 'per_page' => '5']);
    expect($results['music-media']->items()[0]->id)->toBe($asset->id);
    expect(fn () => app(MediaSearchAdapter::class)(['workspace_id' => 'studio', 'per_page' => ['bad']]))->toThrow(ValidationException::class);
    expect(app(MediaSearchAdapter::class)([])->total())->toBe(0);
    expect(fn () => app(MediaSearchAdapter::class)(['workspace_id' => '']))->toThrow(ValidationException::class);
    $this->actingAs(new GenericUser(['id' => 2]));
    expect(fn () => app(MediaSearchAdapter::class)(['workspace_id' => 'studio', 'actor' => $this->actor]))->toThrow(AuthorizationException::class);
});

it('backfills Unicode search names when upgrading existing uploaded assets', function () {
    $asset = ($this->publish)('ÉLECTRIC old recording');
    $this->artisan('migrate:rollback', ['--path' => realpath(__DIR__.'/../../database/migrations'), '--realpath' => true, '--step' => DB::table('migrations')->where('migration', '>=', '2026_11_05_150532_create_music_media_catalog_tables')->count()])->assertSuccessful();
    expect(Schema::hasColumn('music_media_uploads', 'search_name'))->toBeFalse();
    expect(DB::table('music_media_uploads')->where('id', $asset->id)->value('name'))->toBe('ÉLECTRIC old recording');
    $this->artisan('migrate', ['--path' => realpath(__DIR__.'/../../database/migrations'), '--realpath' => true])->assertSuccessful();
    expect(DB::table('music_media_uploads')->where('id', $asset->id)->value('search_name'))->toBe('électric old recording')
        ->and($this->catalog->search($this->actor, new SearchAssets('studio', query: 'électric'))->items()[0]->id)->toBe($asset->id);
});

it('browses authorized catalog entries by stable keyset with bounded queries', function () {
    $ids = [($this->publish)()->id];
    for ($i = 0; $i < 2; $i++) {
        $id = (string) Str::uuid();
        $this->uploads->start($this->actor, new StartUpload('studio', $id, 'Extra', strlen($this->bytes), hash('sha256', $this->bytes), []));
        $this->uploads->append($this->actor, 'studio', $id, 0, $this->bytes);
        $this->uploads->complete($this->actor, 'studio', $id);
        $ids[] = $id;
    }
    rsort($ids);
    DB::enableQueryLog();
    DB::flushQueryLog();
    $first = $this->catalog->browse($this->actor, new BrowseAssets('studio', limit: 1));
    expect(DB::getQueryLog())->toHaveCount(1)->and($first->items[0]->id)->toBe($ids[0])->and($first->nextAssetId)->toBe($ids[0]);
    DB::disableQueryLog();
    $rest = $this->catalog->browse($this->actor, new BrowseAssets('studio', limit: 2, beforeAssetId: $first->nextAssetId));
    expect(array_column($rest->items, 'id'))->toBe(array_slice($ids, 1))->and($rest->nextAssetId)->toBeNull()->and($rest->jsonSerialize()['schema_version'])->toBe(1);
    expect($this->catalog->browse($this->actor, new BrowseAssets('studio', query: 'not present'))->items)->toBe([]);
    expect(fn () => new BrowseAssets('studio', beforeAssetId: 'bad'))->toThrow(ValidationException::class);
});

it('keeps cursor pages workspace scoped and rechecks list and asset permissions', function () {
    $asset = ($this->publish)('Studio take');
    ($this->publish)('Other take', 'other');
    $page = $this->catalog->browse($this->actor, new BrowseAssets('studio'));
    expect(array_column($page->items, 'id'))->toBe([$asset->id]);
    expect(fn () => $this->catalog->browse(new GenericUser(['id' => 2]), new BrowseAssets('studio')))->toThrow(AuthorizationException::class);
    app(Gate::class)->define('music.media.read', fn () => false);
    expect(fn () => $this->catalog->browse($this->actor, new BrowseAssets('studio')))->toThrow(AuthorizationException::class);
});

it('reads current annotated asset metadata with availability and workspace authorization', function () {
    $asset = ($this->publish)();
    $this->catalog->update($this->actor, new UpdateAssetMetadata('studio', $asset->id, 'Edited take', ['Voice'], 0, 'edit'));
    $current = $this->catalog->get($this->actor, 'studio', $asset->id);
    expect($current->name)->toBe('Edited take')->and($current->tags)->toBe(['voice'])->and($current->version)->toBe(1)->and($current->available)->toBeTrue();
    Storage::disk('catalog-test')->delete(DB::table('music_media_uploads')->where('id', $asset->id)->value('blob_key'));
    expect($this->catalog->get($this->actor, 'studio', $asset->id)->available)->toBeFalse();
    expect(fn () => $this->catalog->get($this->actor, 'other', $asset->id))->toThrow(RecordNotFoundException::class);
    expect(fn () => $this->catalog->get(new GenericUser(['id' => 8]), 'studio', $asset->id))->toThrow(AuthorizationException::class);
});

it('distinguishes metadata command conflicts and rejects version counter overflow', function () {
    $asset = ($this->publish)();
    $this->catalog->update($this->actor, new UpdateAssetMetadata('studio', $asset->id, 'First', [], 0, 'key'));
    expect(fn () => $this->catalog->update($this->actor, new UpdateAssetMetadata('studio', $asset->id, 'Different', [], 0, 'key')))->toThrow(MetadataCommandConflict::class);
    expect(fn () => new UpdateAssetMetadata('studio', $asset->id, 'Overflow', [], PHP_INT_MAX, 'overflow'))->toThrow(ValidationException::class);
});
