<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibraryApi\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Liberu\Music\MediaLibrary\MusicMediaLibraryServiceProvider;
use Symfony\Component\HttpFoundation\Response;

final class RequireMedia
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_if(app()->getProvider(MusicMediaLibraryServiceProvider::class) === null, 503);

        return $next($request);
    }
}
