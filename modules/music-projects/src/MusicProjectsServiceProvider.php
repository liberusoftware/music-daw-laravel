<?php

declare(strict_types=1);

namespace Liberu\Music\Projects;

use Illuminate\Support\ServiceProvider;
use Liberu\Music\Projects\Contracts\ProjectAccess;
use Liberu\Music\Projects\Contracts\VerifiedMedia;
use Liberu\Music\Projects\Support\GateProjectAccess;
use Liberu\Music\Projects\Support\UnavailableMedia;

final class MusicProjectsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bindIf(ProjectAccess::class, GateProjectAccess::class);
        $this->app->bindIf(VerifiedMedia::class, UnavailableMedia::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
