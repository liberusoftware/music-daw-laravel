<?php

use Composer\InstalledVersions;
use Illuminate\Filesystem\Filesystem;
use Liberu\Foundation\ModuleManager\Cache\RegistryCache;
use Liberu\Foundation\ModuleManager\Manifest;
use Liberu\Foundation\ModuleManager\ModuleManagerServiceProvider;
use Liberu\Foundation\ModuleManager\ModuleRegistry;
use Liberu\Foundation\ModuleManager\ModuleValidationGuard;
use Liberu\Foundation\ModuleManager\Tests\Fixtures\ProbeProvider;

beforeEach(function () {
    $this->files = new Filesystem();
    $this->deploymentRoot = sys_get_temp_dir().'/module-deployment-'.bin2hex(random_bytes(10));
    $this->files->makeDirectory($this->deploymentRoot.'/probe', 0700, true);
    $this->probeData = ['name' => 'probe', 'display_name' => 'Deployment probe', 'description' => 'Isolated runtime fixture',
        'version' => InstalledVersions::getPrettyVersion('liberusoftware/module-manager'), 'category' => 'foundation', 'provider' => ProbeProvider::class,
        'requires' => ['packages' => []], 'capabilities' => ['fixture.probe'], 'features' => ['Workspace module probes'], 'default_enabled' => true];
    $this->files->put($this->deploymentRoot.'/probe/module.json', json_encode($this->probeData, JSON_THROW_ON_ERROR));
    $this->files->put($this->deploymentRoot.'/probe/composer.json', json_encode(['name' => 'liberusoftware/module-manager',
        'type' => 'liberu-module', 'require' => [], 'extra' => ['liberu' => ['name' => 'probe']]], JSON_THROW_ON_ERROR));
    config()->set('modules.paths', [$this->deploymentRoot]);
    config()->set('modules.cache_path', $this->deploymentRoot.'/registry.cache');
    config()->set('modules.cache', false);
    config()->set('modules.enabled', []);
    config()->set('modules.disabled', []);
});

afterEach(function () {
    $this->files->deleteDirectory($this->deploymentRoot);
});

it('boots a selected dependency provider and exposes installed enabled feature and status diagnostics', function () {
    expect($this->app->bound('module-manager-test-probe'))->toBeFalse();
    (new ModuleManagerServiceProvider($this->app))->register();
    expect($this->app->bound('module-manager-test-probe'))->toBeTrue();
    $this->artisan('module:list')->expectsOutputToContain('probe')->assertSuccessful();
    $this->artisan('module:features', ['query' => ' WORKSPACE '])->expectsOutputToContain('Workspace module probes')->assertSuccessful();
    $this->artisan('module:status', ['name' => 'probe'])->expectsOutputToContain('fixture.probe')->assertSuccessful();
    config()->set('modules.disabled', ['probe']);
    $this->artisan('module:status', ['name' => 'probe'])->expectsOutputToContain('no')->assertSuccessful();
    $this->artisan('module:status', ['name' => 'missing'])->expectsOutputToContain('is not installed')->assertExitCode(1);
});

it('publishes reloads and clears a deployment cache through the public commands', function () {
    $this->artisan('module:cache')->expectsOutputToContain('Module registry cached.')->assertSuccessful();
    $cache = app(RegistryCache::class);
    $registry = $cache->load([], true, config('modules.cache_path'));
    expect($registry->get('probe')->displayName())->toBe('Deployment probe');
    $this->artisan('module:clear')->expectsOutputToContain('Module registry cache cleared.')->assertSuccessful();
    expect($this->files->exists(config('modules.cache_path')))->toBeFalse();
});

it('validates a deployment and reports invalid metadata before registering its provider', function () {
    $manifest = Manifest::fromFile($this->deploymentRoot.'/probe/module.json');
    $this->app->instance(ModuleRegistry::class, new ModuleRegistry(['probe' => $manifest]));
    $this->artisan('module:validate')->expectsOutputToContain('All discovered modules are valid.')->assertSuccessful();
    $invalid = array_replace($this->probeData, ['version' => '99.0.0']);
    $this->files->put($this->deploymentRoot.'/probe/module.json', json_encode($invalid, JSON_THROW_ON_ERROR));
    $registry = new ModuleRegistry(['probe' => Manifest::fromFile($this->deploymentRoot.'/probe/module.json')]);
    $this->app->instance(ModuleRegistry::class, $registry);
    $this->artisan('module:validate')->expectsOutputToContain('installed Composer version does not match')->assertExitCode(1);
    expect(fn () => app(ModuleValidationGuard::class)->ensureValid($registry, app()->version()))->toThrow(RuntimeException::class, 'Module validation failed');
});

it('resolves a compatible installed library without requiring an additional module owner', function () {
    $data = array_replace($this->probeData, ['requires' => ['packages' => ['symfony/console' => '^8.0']]]);
    $this->files->put($this->deploymentRoot.'/probe/module.json', json_encode($data, JSON_THROW_ON_ERROR));
    $manifest = Manifest::fromFile($this->deploymentRoot.'/probe/module.json');
    expect((new ModuleRegistry(['probe' => $manifest]))->resolve([]))->toBe([$manifest]);
});
