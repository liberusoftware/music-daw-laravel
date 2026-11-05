<?php

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Liberu\Foundation\Files\Contracts\MalwareScanner;
use Liberu\Foundation\Search\Services\SearchService;
use Liberu\Music\MediaLibrary\Commands\StartUpload;
use Liberu\Music\MediaLibrary\Commands\UpdateAssetMetadata;
use Liberu\Music\MediaLibrary\MusicMediaLibraryServiceProvider;
use Liberu\Music\MediaLibrary\Services\MediaCatalog;
use Liberu\Music\MediaLibrary\Services\MediaUploads;

it('finds tagged verified audio through the host foundation registry without widening workspace access', function () {
    $this->app->register(MusicMediaLibraryServiceProvider::class);
    $this->artisan('migrate', ['--path' => base_path('modules/music-media-library/database/migrations'), '--realpath' => true])->assertSuccessful();
    config()->set('music-media.disk', 'host-media-catalog');
    Storage::fake('host-media-catalog');
    $actor = User::factory()->create();
    foreach (['upload', 'read', 'annotate', 'list'] as $operation) {
        Gate::define('music.media.'.$operation, fn ($user, $workspace) => $user->getKey() === $actor->getKey() && $workspace === 'studio');
    }
    $scanner = Mockery::mock(MalwareScanner::class);
    $scanner->shouldReceive('clean')->once()->andReturn(true);
    app()->instance(MalwareScanner::class, $scanner);
    $format = pack('vvVVvv', 1, 1, 48000, 96000, 2, 16);
    $chunks = 'fmt '.pack('V', 16).$format.'data'.pack('V', 4).pack('v*', 0, 16384);
    $bytes = 'RIFF'.pack('V', strlen($chunks) + 4).'WAVE'.$chunks;
    $id = (string) Str::uuid();
    $uploads = app(MediaUploads::class);
    $uploads->start($actor, new StartUpload('studio', $id, 'Original', strlen($bytes), hash('sha256', $bytes)));
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
    $this->actingAs(User::factory()->create());
    expect(fn () => $search->searchAll(['types' => ['music-media'], 'workspace_id' => 'studio']))->toThrow(AuthorizationException::class);
});
