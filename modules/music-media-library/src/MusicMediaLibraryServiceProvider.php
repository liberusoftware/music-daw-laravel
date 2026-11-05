<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibrary;

use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\ServiceProvider;
use Liberu\Foundation\Search\Registry\SearcherRegistry;
use Liberu\Music\MediaLibrary\Contracts\MediaAuthorizer;
use Liberu\Music\MediaLibrary\Services\AssetWaveforms;
use Liberu\Music\MediaLibrary\Services\MediaCatalog;
use Liberu\Music\MediaLibrary\Services\MediaSearchAdapter;
use Liberu\Music\MediaLibrary\Services\MediaUploads;
use Liberu\Music\MediaLibrary\Support\GateMediaAuthorizer;
use Liberu\Music\MediaLibrary\Support\ScopedMediaAccess;

final class MusicMediaLibraryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/music-media.php', 'music-media');
        $this->app->bindIf(MediaAuthorizer::class, GateMediaAuthorizer::class);
        $this->app->when(ScopedMediaAccess::class)->needs(UserProvider::class)->give(fn () => $this->app->make('auth')->createUserProvider(config('auth.guards.'.config('auth.defaults.guard').'.provider')));
        $this->app->when([MediaUploads::class, AssetWaveforms::class, MediaCatalog::class])->needs(Filesystem::class)->give(fn () => $this->app->make('filesystem')->disk(config('music-media.disk')));
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        if ($this->app->bound(SearcherRegistry::class)) {
            $this->app->make(SearcherRegistry::class)->register('music-media', fn (array $filters) => $this->app->make(MediaSearchAdapter::class)($filters));
        }
    }
}
