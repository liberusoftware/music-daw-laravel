<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibraryApi;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\ServiceProvider;
use Liberu\Music\MediaLibraryApi\Support\ApiProblems;

final class MusicMediaLibraryApiServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->callAfterResolving(ExceptionHandler::class, function ($handler): void {
            if (method_exists($handler, 'renderable')) {
                $handler->renderable([new ApiProblems(), 'render']);
            }
        });
        $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
    }
}
