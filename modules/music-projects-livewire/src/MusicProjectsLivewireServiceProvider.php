<?php

declare(strict_types=1);

namespace Liberu\Music\ProjectsLivewire;

use Illuminate\Support\ServiceProvider;
use Liberu\Music\ProjectsLivewire\Livewire\SessionWorkspace;
use Livewire\Livewire;

final class MusicProjectsLivewireServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'music-projects-livewire');
        Livewire::component('music-session-workspace', SessionWorkspace::class);
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
    }
}
