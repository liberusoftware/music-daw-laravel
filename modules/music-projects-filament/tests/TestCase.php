<?php

namespace Liberu\Music\ProjectsFilament\Tests;

use Filament\Facades\Filament;
use Liberu\Music\ProjectsFilament\Tests\Fixtures\TestPanelProvider;
use Liberu\PackageTestbench\PackageTestCase;

abstract class TestCase extends PackageTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel('music-test');
    }

    protected function getPackageProviders($app): array
    {
        // Filament needs transitive UI providers, as it does in a normal Laravel host.
        $installed = json_decode(file_get_contents($this->packageRoot().'/vendor/composer/installed.json'), true, flags: JSON_THROW_ON_ERROR);
        $providers = [];
        foreach ($installed['packages'] ?? [] as $package) {
            foreach ($package['extra']['laravel']['providers'] ?? [] as $provider) {
                $providers[] = $provider;
            }
        }

        return array_values(array_unique([...$providers, ...parent::getPackageProviders($app), TestPanelProvider::class]));
    }
}
