<?php

declare(strict_types=1);

namespace Liberu\Music\ProjectsApi;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\ServiceProvider;
use Liberu\Music\ProjectsApi\Support\ApiProblems;

final class MusicProjectsApiServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->callAfterResolving(ExceptionHandler::class, function ($handler): void {
            $handler->renderable([new ApiProblems(), 'render']);
        });
        $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
    }
}
