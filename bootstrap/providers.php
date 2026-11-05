<?php

use App\Providers\Filament\AdminPanelProvider;
use App\Providers\Filament\AppPanelProvider;
use App\Providers\MusicModuleIntegrationProvider;
use Liberu\Foundation\ModuleManager\ModuleManagerServiceProvider;

return [
    ModuleManagerServiceProvider::class,
    AdminPanelProvider::class,
    AppPanelProvider::class,
    MusicModuleIntegrationProvider::class,
];
