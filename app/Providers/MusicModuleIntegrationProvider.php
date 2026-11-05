<?php

namespace App\Providers;

use App\Support\UploadedSessionMedia;
use App\Support\UploadedSessionRetention;
use Illuminate\Support\ServiceProvider;
use Liberu\Foundation\Files\Contracts\MediaAccess;
use Liberu\Music\MediaLibrary\MusicMediaLibraryServiceProvider;
use Liberu\Music\MediaLibrary\Support\ScopedMediaAccess;
use Liberu\Music\Projects\Contracts\SessionMediaRetention;
use Liberu\Music\Projects\Contracts\VerifiedMedia;
use Liberu\Music\Projects\MusicProjectsServiceProvider;
use Liberu\Music\Projects\Services\ProjectSessions;

final class MusicModuleIntegrationProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->getProvider(MusicProjectsServiceProvider::class) === null
            || $this->app->getProvider(MusicMediaLibraryServiceProvider::class) === null) {
            return;
        }
        $this->app->bind(VerifiedMedia::class, UploadedSessionMedia::class);
        $this->app->when(ProjectSessions::class)->needs(SessionMediaRetention::class)->give(UploadedSessionRetention::class);
        $this->app->when(ProjectSessions::class)->needs(MediaAccess::class)->give(ScopedMediaAccess::class);
    }
}
