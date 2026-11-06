<?php

use Illuminate\Filesystem\Filesystem;
use Liberu\Foundation\ModuleManager\ModuleManagerServiceProvider;

it('clears a corrupt deployment cache before loading it for the recovery command', function (array $arguments) {
    $files = new Filesystem();
    $path = tempnam(sys_get_temp_dir(), 'module-recovery-');
    $files->put($path, serialize(new stdClass()));
    config()->set('modules.cache', true);
    config()->set('modules.cache_path', $path);
    $originalArguments = $_SERVER['argv'] ?? [];
    $_SERVER['argv'] = ['artisan', ...$arguments];
    try {
        (new ModuleManagerServiceProvider($this->app))->register();
        $this->artisan('module:clear')->expectsOutputToContain('Module registry cache cleared.')->assertSuccessful();
        expect($files->exists($path))->toBeFalse();
    } finally {
        $_SERVER['argv'] = $originalArguments;
        $files->delete($path);
    }
})->with([
    'plain command' => [['module:clear']],
    'leading boolean console option' => [['--no-interaction', 'module:clear']],
    'leading environment option' => [['--env=testing', 'module:clear']],
]);
