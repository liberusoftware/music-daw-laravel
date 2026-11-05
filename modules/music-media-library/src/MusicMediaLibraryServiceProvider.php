<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibrary;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\ServiceProvider;
use Liberu\Music\MediaLibrary\Contracts\MediaAuthorizer;
use Liberu\Music\MediaLibrary\Services\AssetWaveforms;
use Liberu\Music\MediaLibrary\Services\MediaUploads;
use Liberu\Music\MediaLibrary\Support\GateMediaAuthorizer;

final class MusicMediaLibraryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/music-media.php', 'music-media');
        $this->app->bindIf(MediaAuthorizer::class, GateMediaAuthorizer::class);
        $this->app->when([MediaUploads::class, AssetWaveforms::class])->needs(Filesystem::class)->give(fn () => $this->app->make('filesystem')->disk(config('music-media.disk')));
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
