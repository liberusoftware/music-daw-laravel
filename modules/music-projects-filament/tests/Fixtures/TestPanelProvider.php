<?php

namespace Liberu\Music\ProjectsFilament\Tests\Fixtures;

use Filament\Panel;
use Filament\PanelProvider;
use Liberu\Music\ProjectsFilament\MusicProjectsFilamentPlugin;

final class TestPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel->id('music-test')->path('music-test')->plugins([MusicProjectsFilamentPlugin::make()]);
    }
}
