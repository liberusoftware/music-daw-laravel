<?php

declare(strict_types=1);

namespace Liberu\Music\ProjectsApi\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Liberu\Music\Projects\MusicProjectsServiceProvider;
use Symfony\Component\HttpFoundation\Response;

final class RequireProjects
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_if(app()->getProvider(MusicProjectsServiceProvider::class) === null, 503);

        return $next($request);
    }
}
