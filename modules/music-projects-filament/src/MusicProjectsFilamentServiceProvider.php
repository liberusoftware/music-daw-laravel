<?php

declare(strict_types=1);

namespace Liberu\Music\ProjectsFilament;

use Illuminate\Support\ServiceProvider;
use Liberu\Music\ProjectsFilament\Contracts\WorkspaceContext;
use Liberu\Music\ProjectsFilament\Support\FilamentWorkspaceContext;
use Liberu\Music\ProjectsFilament\Support\FilamentWorkspaceScope;
use Liberu\Music\ProjectsLivewire\Contracts\WorkspaceScope;

final class MusicProjectsFilamentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bindIf(WorkspaceContext::class, FilamentWorkspaceContext::class);
        $this->app->bind(WorkspaceScope::class, FilamentWorkspaceScope::class);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'music-projects-filament');
    }
}
