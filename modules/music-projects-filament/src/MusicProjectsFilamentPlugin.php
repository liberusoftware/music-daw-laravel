<?php

declare(strict_types=1);

namespace Liberu\Music\ProjectsFilament;

use Filament\Contracts\Plugin;
use Filament\Panel;
use Illuminate\Http\Middleware\SetCacheHeaders;
use Liberu\Music\ProjectsFilament\Pages\Sessions;

final class MusicProjectsFilamentPlugin implements Plugin
{
    public static function make(): self
    {
        return new self();
    }

    public function getId(): string
    {
        return 'liberu-music-projects';
    }

    public function register(Panel $panel): void
    {
        $panel->pages([Sessions::class])->middleware([SetCacheHeaders::class.':no_store;private']);
    }

    public function boot(Panel $panel): void {}
}
