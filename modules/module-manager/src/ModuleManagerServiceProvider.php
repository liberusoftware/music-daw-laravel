<?php

namespace Liberu\Foundation\ModuleManager;

use Illuminate\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Liberu\Foundation\ModuleManager\Cache\RegistryCache;
use Liberu\Foundation\ModuleManager\Console\CacheModulesCommand;
use Liberu\Foundation\ModuleManager\Console\ClearModulesCommand;
use Liberu\Foundation\ModuleManager\Console\ListFeaturesCommand;
use Liberu\Foundation\ModuleManager\Console\ListModulesCommand;
use Liberu\Foundation\ModuleManager\Console\ModuleStatusCommand;
use Liberu\Foundation\ModuleManager\Console\ValidateModulesCommand;
use Symfony\Component\Console\Input\ArgvInput;

final class ModuleManagerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/modules.php', 'modules');
        $this->app->singleton(RegistryCache::class);

        // Recovery must remove a corrupt cache before normal provider loading
        // tries to deserialize it. Other commands continue to fail closed.
        if ($this->app->runningInConsole() && (new ArgvInput())->getFirstArgument() === 'module:clear') {
            $this->app->make(RegistryCache::class)->clear((string) config('modules.cache_path'));
        }

        $this->app->singleton(ModuleRegistry::class, fn () => $this->app->make(RegistryCache::class)->load(
            (array) config('modules.paths', [base_path('modules')]),
            (bool) config('modules.cache', false),
            (string) config('modules.cache_path'),
        ));

        $registry = $this->app->make(ModuleRegistry::class);
        $modules = $registry->resolve(config('modules.enabled', []), config('modules.disabled', []));
        $selected = [];
        foreach ($modules as $module) {
            $selected[$module->name()] = $module;
        }

        $this->app->make(ModuleValidationGuard::class)
            ->ensureValid(new ModuleRegistry($selected), Application::VERSION);

        foreach ($modules as $module) {
            if ($module->name() !== 'module-manager') {
                $this->app->register($module->provider());
            }
        }
    }

    public function boot(): void
    {
        $this->publishes([__DIR__.'/../config/modules.php' => config_path('modules.php')], 'modules-config');

        if ($this->app->runningInConsole()) {
            $this->commands([CacheModulesCommand::class, ClearModulesCommand::class, ListFeaturesCommand::class, ListModulesCommand::class, ModuleStatusCommand::class, ValidateModulesCommand::class]);
        }
    }
}
