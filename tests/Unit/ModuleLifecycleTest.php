<?php

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Liberu\Foundation\ModuleManager\Cache\RegistryCache;
use Liberu\Foundation\ModuleManager\Exceptions\DependencyResolutionFailed;
use Liberu\Foundation\ModuleManager\Exceptions\InvalidManifest;
use Liberu\Foundation\ModuleManager\Manifest;
use Liberu\Foundation\ModuleManager\ModuleDiscovery;
use Liberu\Foundation\ModuleManager\ModuleRegistry;
use Liberu\Foundation\ModuleManager\ModuleValidationGuard;
use Liberu\Foundation\ModuleManager\ModuleValidator;
use Liberu\Music\Projects\MusicProjectsServiceProvider;

beforeEach(function () {
    $this->files = new Filesystem();
    $this->moduleRoot = sys_get_temp_dir().'/music-module-lifecycle-'.Str::random(20);
    $this->files->makeDirectory($this->moduleRoot);
    $this->makeModule = function (string $name, array $overrides = [], array $composerOverrides = []): Manifest {
        $directory = $this->moduleRoot.'/'.$name;
        $this->files->ensureDirectoryExists($directory);
        $data = array_replace([
            'name' => $name, 'display_name' => 'Music fixture', 'description' => 'Isolated host lifecycle fixture',
            'version' => '1.0.0', 'category' => 'product', 'provider' => MusicProjectsServiceProvider::class,
            'requires' => ['packages' => [], 'capabilities' => []], 'capabilities' => ['fixture.'.$name],
            'features' => ['Revision checkpoints', 'Portable manifests'], 'default_enabled' => true,
        ], $overrides);
        $composer = array_replace(['name' => 'test-fixtures/'.$name, 'type' => 'liberu-module',
            'require' => [], 'extra' => ['liberu' => ['name' => $data['name']]]], $composerOverrides);
        $this->files->put($directory.'/module.json', json_encode($data, JSON_THROW_ON_ERROR));
        $this->files->put($directory.'/composer.json', json_encode($composer, JSON_THROW_ON_ERROR));

        return Manifest::fromFile($directory.'/module.json');
    };
});

afterEach(function () {
    $this->files->deleteDirectory($this->moduleRoot);
});

it('orders mixed package and capability dependencies and honors explicit disablement', function () {
    $base = ($this->makeModule)('music-base');
    $bridge = ($this->makeModule)('music-bridge', ['requires' => ['packages' => ['test-fixtures/music-base' => '^1.0']]]);
    $surface = ($this->makeModule)('music-surface', ['default_enabled' => false,
        'requires' => ['capabilities' => ['fixture.music-bridge' => '^1.0']]]);
    $registry = new ModuleRegistry(['music-surface' => $surface, 'music-bridge' => $bridge, 'music-base' => $base]);
    expect(array_map(fn ($item) => $item->name(), $registry->resolve(['music-surface'])))->toBe(['music-base', 'music-bridge', 'music-surface'])
        ->and($registry->enabled('music-surface'))->toBeFalse()->and($registry->enabled('music-surface', ['music-surface']))->toBeTrue()
        ->and($registry->enabled('missing'))->toBeFalse()->and($registry->get('missing'))->toBeNull();
    expect(fn () => $registry->resolve(['music-surface'], ['music-base']))->toThrow(DependencyResolutionFailed::class, 'requires enabled package');
    expect($registry->resolve([], ['music-base', 'music-bridge', 'music-surface']))->toBe([]);
});

it('rejects missing incompatible disabled and cyclic dependencies before booting a provider', function (array $requires, string $message, bool $disableBase) {
    $base = ($this->makeModule)('music-base');
    $consumer = ($this->makeModule)('music-consumer', ['requires' => $requires]);
    $registry = new ModuleRegistry(['music-base' => $base, 'music-consumer' => $consumer]);
    expect(fn () => $registry->resolve([], $disableBase ? ['music-base'] : []))->toThrow(DependencyResolutionFailed::class, $message);
})->with([
    'missing library' => [['packages' => ['test-fixtures/missing' => '^1.0']], 'missing or incompatible library', false],
    'incompatible installed library' => [['packages' => ['psr/log' => '^999.0']], 'missing or incompatible library', false],
    'incompatible module' => [['packages' => ['test-fixtures/music-base' => '^2.0']], 'installed manifest is', false],
    'disabled module' => [['packages' => ['test-fixtures/music-base' => '^1.0']], 'requires enabled package', true],
    'missing capability' => [['capabilities' => ['fixture.missing' => '^1.0']], 'missing capability', false],
    'disabled capability owner' => [['capabilities' => ['fixture.music-base' => '^1.0']], 'requires enabled capability', true],
    'incompatible capability owner' => [['capabilities' => ['fixture.music-base' => '^2.0']], 'requires enabled capability', false],
    'self cycle' => [['capabilities' => ['fixture.music-consumer' => '^1.0']], 'Circular module dependency', false],
]);

it('accepts a compatible installed library without treating it as a runtime module', function () {
    $module = ($this->makeModule)('music-library-client', ['requires' => ['packages' => ['psr/log' => '^3.0']]]);
    expect((new ModuleRegistry(['music-library-client' => $module]))->resolve([]))->toBe([$module]);
});

it('searches the feature catalogue independently of runtime enablement', function () {
    $module = ($this->makeModule)('music-catalogue', ['default_enabled' => false]);
    $registry = new ModuleRegistry(['music-catalogue' => $module]);
    expect($registry->searchFeatures(' CHECKPOINT '))->toBe(['music-catalogue' => ['Revision checkpoints']])
        ->and($registry->searchFeatures('no-match'))->toBe([])
        ->and($registry->searchFeatures())->toBe(['music-catalogue' => ['Revision checkpoints', 'Portable manifests']])
        ->and($registry->providingFeature(' REVISION CHECKPOINTS '))->toBe([$module])
        ->and($registry->providingFeature('checkpoint'))->toBe([])->and($registry->enabled('music-catalogue'))->toBeFalse();
    $this->app->instance(ModuleRegistry::class, $registry);
    $this->artisan('module:features', ['query' => 'checkpoint'])->expectsOutputToContain('Revision checkpoints')->assertSuccessful();
});

it('round trips a deployment cache and rediscovers changes only after it is cleared', function () {
    $module = ($this->makeModule)('music-cached');
    $cache = new RegistryCache();
    $path = $this->moduleRoot.'/cache/registry.cache';
    $cache->write(new ModuleRegistry(['music-cached' => $module]), $path);
    ($this->makeModule)('music-cached', ['version' => '2.0.0']);
    expect($cache->load([$this->moduleRoot], true, $path)->get('music-cached')->version())->toBe('1.0.0');
    $cache->clear($path);
    expect($cache->load([$this->moduleRoot], true, $path)->get('music-cached')->version())->toBe('2.0.0');
    $cache->clear($path);
    $this->files->put($path, serialize(new stdClass()));
    expect(fn () => $cache->load([], true, $path))->toThrow(RuntimeException::class, 'module registry cache is invalid');
});

it('rejects malformed registry payloads but reads the legacy private-property cache layout', function () {
    $module = ($this->makeModule)('music-legacy');
    $registry = new ModuleRegistry([]);
    foreach ([[], ['modules' => 'bad'], ['modules' => [new stdClass()]]] as $payload) {
        expect(fn () => $registry->__unserialize($payload))->toThrow(RuntimeException::class, 'module registry cache is invalid');
    }
    $registry->__unserialize(["\0".ModuleRegistry::class."\0modules" => ['music-legacy' => $module]]);
    expect($registry->get('music-legacy'))->toBe($module)
        ->and(unserialize(serialize($registry))->get('music-legacy')->toArray())->toBe($module->toArray());
});

it('reports cache write failures instead of claiming a deployment cache was published', function (bool $writeSucceeds) {
    $files = Mockery::mock(Filesystem::class);
    $files->shouldReceive('isDirectory')->once()->andReturnTrue();
    $files->shouldReceive('put')->once()->andReturn($writeSucceeds ? 1 : false);
    $files->shouldReceive('move')->times($writeSucceeds ? 1 : 0)->andReturnFalse();
    expect(fn () => (new RegistryCache($files))->write(new ModuleRegistry([]), $this->moduleRoot.'/cache'))
        ->toThrow(RuntimeException::class, 'Unable to write');
})->with([false, true]);

it('reports a cache clear failure rather than silently retaining a stale deployment', function () {
    $files = Mockery::mock(Filesystem::class);
    $files->shouldReceive('isFile')->once()->andReturnTrue();
    $files->shouldReceive('delete')->once()->andReturnFalse();
    expect(fn () => (new RegistryCache($files))->clear($this->moduleRoot.'/cache'))->toThrow(RuntimeException::class, 'Unable to clear');
});

it('discovers mirrored copies once and rejects conflicting identities or capabilities', function () {
    $original = ($this->makeModule)('music-original');
    $this->files->copyDirectory($original->path, $this->moduleRoot.'/music-copy');
    $registry = (new ModuleDiscovery())->discover([$this->moduleRoot]);
    expect($registry->get('music-original')->version())->toBe('1.0.0');
    ($this->makeModule)('music-extra', ['capabilities' => ['fixture.music-original']]);
    expect(fn () => (new ModuleDiscovery())->discover([$this->moduleRoot]))->toThrow(InvalidManifest::class, 'Duplicate capability');
    $this->files->deleteDirectory($this->moduleRoot.'/music-extra');
    $this->files->put($this->moduleRoot.'/music-copy/module.json', json_encode(array_replace($original->toArray(), ['version' => '2.0.0']), JSON_THROW_ON_ERROR));
    expect(fn () => (new ModuleDiscovery())->discover([$this->moduleRoot]))->toThrow(InvalidManifest::class, 'Duplicate module');
});

it('rejects packages without an unambiguous Composer identity during discovery', function (string $fault) {
    $module = ($this->makeModule)('music-discovery');
    if ($fault === 'missing-file') {
        $this->files->delete($module->path.'/composer.json');
    } elseif ($fault === 'missing-name') {
        $this->files->put($module->path.'/composer.json', '{}');
    } else {
        ($this->makeModule)('music-other', [], ['name' => 'test-fixtures/music-discovery']);
    }
    expect(fn () => (new ModuleDiscovery())->discover([$this->moduleRoot]))->toThrow(InvalidManifest::class);
})->with(['missing-file', 'missing-name', 'duplicate-package']);

it('fails deployment validation with actionable Composer provider compatibility and presentation errors', function (array $manifest, array $composer, string $message) {
    $module = ($this->makeModule)('music-validation', array_replace(['version' => '0.1.0'], $manifest),
        array_replace(['name' => 'liberusoftware/music-projects'], $composer));
    $registry = new ModuleRegistry(['music-validation' => $module]);
    $errors = (new ModuleValidator())->validate($registry, app()->version());
    expect(implode("\n", $errors))->toContain($message);
    expect(fn () => app(ModuleValidationGuard::class)->ensureValid($registry, app()->version()))->toThrow(RuntimeException::class, 'Module validation failed');
})->with([
    'mismatched identity' => [[], ['extra' => ['liberu' => ['name' => 'other']]], 'extra.liberu.name does not match'],
    'wrong package type' => [[], ['type' => 'library'], 'type must be liberu-module'],
    'not installed' => [[], ['name' => 'test-fixtures/not-installed'], 'Composer package is not installed'],
    'version drift' => [['version' => '99.0.0'], [], 'installed Composer version does not match'],
    'dependency drift' => [[], ['require' => ['liberusoftware/settings' => '^1.4']], 'Composer and manifest Liberu dependencies differ'],
    'missing provider' => [['provider' => 'MissingMusicProvider'], [], 'is not autoloadable'],
    'invalid provider' => [['provider' => stdClass::class], [], 'provider must extend'],
    'incompatible PHP' => [['requires' => ['php' => '^99.0']], [], 'PHP '],
    'incompatible Laravel' => [['requires' => ['laravel' => '^99.0']], [], 'Laravel '],
    'invalid presentation owner' => [['presentation' => ['filament' => ['app' => [stdClass::class]]]], [], 'only presentation modules'],
    'unavailable plugin' => [['category' => 'presentation', 'presentation' => ['filament' => ['app' => ['MissingMusicPlugin']]]], [], 'presentation plugin MissingMusicPlugin is not autoloadable'],
    'dependency resolution' => [['requires' => ['packages' => ['test-fixtures/missing' => '^1.0']]], ['require' => ['test-fixtures/missing' => '^1.0']], 'missing or incompatible library'],
]);

it('validates the real installed host module composition through the deployment command', function () {
    $this->artisan('module:validate')->expectsOutputToContain('All discovered modules are valid.')->assertSuccessful();
});

it('rejects malformed module declarations before runtime resolution', function (array $mutation, string $message) {
    expect(fn () => ($this->makeModule)('music-malformed', $mutation))->toThrow(InvalidManifest::class, $message);
})->with([
    'invalid name' => [['name' => 'music/bad'], 'invalid module name'],
    'invalid category' => [['category' => 'unknown'], 'invalid category'],
    'nonboolean enablement' => [['default_enabled' => 'true'], 'must be boolean'],
    'invalid capability' => [['capabilities' => ['Bad capability']], 'invalid capability'],
    'missing features' => [['features' => []], 'at least one feature'],
    'duplicate feature' => [['features' => ['Checkpoint', 'checkpoint']], 'duplicate features'],
    'invalid feature type' => [['features' => [null]], 'invalid feature'],
    'invalid dependency shape' => [['requires' => 'bad'], 'invalid requires'],
]);

it('rejects incomplete and syntactically broken manifests with their original diagnostics', function () {
    $module = ($this->makeModule)('music-incomplete');
    $data = $module->toArray();
    unset($data['provider']);
    $this->files->put($module->path.'/module.json', json_encode($data, JSON_THROW_ON_ERROR));
    expect(fn () => Manifest::fromFile($module->path.'/module.json'))->toThrow(InvalidManifest::class, 'missing required key [provider]');
    $this->files->put($module->path.'/module.json', '{');
    expect(fn () => Manifest::fromFile($module->path.'/module.json'))->toThrow(JsonException::class);
});

it('returns a failing deployment command status when package installation metadata is incomplete', function () {
    $module = ($this->makeModule)('music-uninstalled');
    $this->app->instance(ModuleRegistry::class, new ModuleRegistry(['music-uninstalled' => $module]));
    $this->artisan('module:validate')->expectsOutputToContain('Composer package is not installed.')->assertExitCode(1);
});
