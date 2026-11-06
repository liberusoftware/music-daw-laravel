<?php

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

it('recovers a corrupt registry through a fresh Artisan boot with cached Laravel configuration', function () {
    $files = new Filesystem();
    $directory = sys_get_temp_dir().'/music-registry-recovery-'.Str::random(20);
    $files->makeDirectory($directory, 0700);
    $files->makeDirectory($directory.'/views', 0700);
    $files->put($directory.'/database.sqlite', '');
    $cache = $directory.'/registry.cache';
    $config = $directory.'/config.php';
    $environment = [
        'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_KEY' => (string) config('app.key'),
        'APP_CONFIG_CACHE' => $config, 'APP_ROUTES_CACHE' => $directory.'/routes.php',
        'VIEW_COMPILED_PATH' => $directory.'/views', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $directory.'/database.sqlite', 'DB_URL' => '',
        'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array',
        'MODULES_CACHE' => 'false', 'MODULES_ENABLED' => '', 'MODULES_DISABLED' => '', 'MUSIC_RECOVERY_CACHE' => $cache,
    ];
    try {
        $compile = new Process([PHP_BINARY, '-r', <<<'CODE'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$configuration = $app['config']->all();
$configuration['modules']['cache'] = true;
$configuration['modules']['cache_path'] = getenv('MUSIC_RECOVERY_CACHE');
file_put_contents(getenv('APP_CONFIG_CACHE'), '<?php return '.var_export($configuration, true).';');
chmod(getenv('APP_CONFIG_CACHE'), 0600);
CODE], base_path(), $environment);
        $compile->setTimeout(60)->mustRun();
        $configHash = hash_file('sha256', $config);
        $payload = serialize(new stdClass());
        $files->put($cache, $payload);
        $ordinary = new Process([PHP_BINARY, 'artisan', 'module:list', '--no-interaction'], base_path(), $environment);
        $ordinary->setTimeout(60)->run();
        expect($ordinary->isSuccessful())->toBeFalse()->and($ordinary->getErrorOutput().$ordinary->getOutput())->toContain('module registry cache is invalid')
            ->and($files->get($cache))->toBe($payload);
        foreach ([['module:clear'], ['--no-interaction', 'module:clear'], ['--env=testing', 'module:clear']] as $arguments) {
            $files->put($cache, $payload);
            $clear = new Process([PHP_BINARY, 'artisan', ...$arguments], base_path(), $environment);
            $clear->setTimeout(60)->mustRun();
            expect($clear->getOutput())->toContain('Module registry cache cleared.')
                ->and($files->exists($cache))->toBeFalse()->and(hash_file('sha256', $config))->toBe($configHash);
        }
        $reopened = new Process([PHP_BINARY, 'artisan', 'module:validate', '--no-interaction'], base_path(), $environment);
        $reopened->setTimeout(60)->mustRun();
        expect($reopened->getOutput())->toContain('All discovered modules are valid.')->and(hash_file('sha256', $config))->toBe($configHash);
    } finally {
        $files->deleteDirectory($directory);
    }
});
