<?php

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Liberu\Foundation\Files\Contracts\MalwareScanner;
use Liberu\Foundation\ImportExport\Contracts\TransferAuthorizer;
use Liberu\Foundation\Search\Services\SearchService;
use Liberu\Music\MediaLibrary\Commands\SaveMediaPack;
use Liberu\Music\MediaLibrary\Commands\StartUpload;
use Liberu\Music\MediaLibrary\Commands\UpdateAssetMetadata;
use Liberu\Music\MediaLibrary\MusicMediaLibraryServiceProvider;
use Liberu\Music\MediaLibrary\Services\MediaCatalog;
use Liberu\Music\MediaLibrary\Services\MediaPacks;
use Liberu\Music\MediaLibrary\Services\MediaRetention;
use Liberu\Music\MediaLibrary\Services\MediaUploads;
use Liberu\Music\MediaLibrary\Services\PackArchives;

it('finds tagged verified audio through the host foundation registry without widening workspace access', function () {
    $this->app->register(MusicMediaLibraryServiceProvider::class);
    $this->artisan('migrate', ['--path' => base_path('modules/music-media-library/database/migrations'), '--realpath' => true])->assertSuccessful();
    config()->set('music-media.disk', 'host-media-catalog');
    Storage::fake('host-media-catalog');
    $actor = User::factory()->create();
    foreach (['upload', 'read', 'annotate', 'list', 'retain', 'pack_write', 'pack_read', 'pack_export', 'pack_import', 'distribute'] as $operation) {
        Gate::define('music.media.'.$operation, fn ($user, $workspace) => $user->getKey() === $actor->getKey() && $workspace === 'studio');
    }
    Gate::define('music.media.pack_distribute', fn ($user, $pack) => $user->getKey() === $actor->getKey() && $pack->workspaceId === 'studio' && $pack->rights['license'] === 'CC-BY-4.0');
    $scanner = Mockery::mock(MalwareScanner::class);
    $scanner->shouldReceive('clean')->twice()->andReturn(true);
    app()->instance(MalwareScanner::class, $scanner);
    $format = pack('vvVVvv', 1, 1, 48000, 96000, 2, 16);
    $chunks = 'fmt '.pack('V', 16).$format.'data'.pack('V', 4).pack('v*', 0, 16384);
    $bytes = 'RIFF'.pack('V', strlen($chunks) + 4).'WAVE'.$chunks;
    $id = (string) Str::uuid();
    $uploads = app(MediaUploads::class);
    $rights = ['source' => 'Studio recording', 'license' => 'CC-BY-4.0', 'attribution' => 'Studio musician'];
    $uploads->start($actor, new StartUpload('studio', $id, 'Original', strlen($bytes), hash('sha256', $bytes), $rights));
    $uploads->append($actor, 'studio', $id, 0, $bytes);
    $uploads->complete($actor, 'studio', $id);
    app(MediaCatalog::class)->update($actor, new UpdateAssetMetadata('studio', $id, 'Kick one', ['DRUMS'], 0, 'tag'));
    $this->actingAs($actor);
    $search = app(SearchService::class);
    $page = $search->searchAll(['types' => ['music-media'], 'workspace_id' => 'studio', 'query' => 'kick', 'tags' => ['drums']])['music-media'];
    expect($page->total())->toBe(1)->and($page->items()[0]->id)->toBe($id)
        ->and($page->items()[0]->available)->toBeTrue()->and($page->items()[0]->jsonSerialize())->not->toHaveKey('blob_key');
    expect($search->searchAll(['types' => ['music-media'], 'query' => 'kick'])['music-media']->total())->toBe(0);
    expect(fn () => $search->searchAll(['types' => ['music-media'], 'workspace_id' => 'other']))->toThrow(AuthorizationException::class);
    $packs = app(MediaPacks::class);
    $packId = (string) Str::uuid();
    $pack = $packs->save($actor, new SaveMediaPack('studio', $packId, 'Drum samples', [$id], $rights, 0, 'create'));
    expect(app(MediaRetention::class)->isProtected($actor, 'studio', $id))->toBeTrue();
    expect(fn () => $packs->export($actor, 'studio', $packId))->toThrow(AuthorizationException::class, 'foundation');
    $transfers = Mockery::mock(TransferAuthorizer::class);
    $transfers->shouldReceive('allowed')->with((string) $actor->getKey(), 'music-media-pack', Mockery::any())->andReturn(true);
    app()->instance(TransferAuthorizer::class, $transfers);
    $packs = app(MediaPacks::class);
    $manifest = $packs->export($actor, 'studio', $packId);
    $copy = $packs->import($actor, 'studio', (string) Str::uuid(), $manifest, [$id => $id], 'import');
    expect($copy->assets)->toBe($pack->assets)->and($copy->rights)->toBe($rights);
    $archive = tmpfile();
    try {
        $archives = app(PackArchives::class);
        $receipt = $archives->exportTo($actor, 'studio', $packId, $archive);
        rewind($archive);
        $destinationId = (string) Str::uuid();
        $unpacked = $archives->importFrom($actor, 'studio', (string) Str::uuid(), $archive, [$id => $destinationId], 'receive-archive');
        expect($receipt->mediaObjects)->toBe(1)->and($unpacked->assets[0]['reference']['id'])->toBe($destinationId)
            ->and($unpacked->rights)->toBe($rights)->and($uploads->get($actor, 'studio', $destinationId)->metadata['sha256'])->toBe(hash('sha256', $bytes));
    } finally {
        fclose($archive);
    }
    Gate::define('music.media.distribute', fn () => false);
    expect(fn () => $packs->export($actor, 'studio', $packId))->toThrow(AuthorizationException::class);
    expect(fn () => $packs->get(User::factory()->create(), 'studio', $packId))->toThrow(AuthorizationException::class);
    $this->actingAs(User::factory()->create());
    expect(fn () => $search->searchAll(['types' => ['music-media'], 'workspace_id' => 'studio']))->toThrow(AuthorizationException::class);
});
