<?php

namespace Liberu\Foundation\ModuleManager\Tests\Fixtures;

use Illuminate\Support\ServiceProvider;
use stdClass;

final class ProbeProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->instance('module-manager-test-probe', new stdClass());
    }
}
