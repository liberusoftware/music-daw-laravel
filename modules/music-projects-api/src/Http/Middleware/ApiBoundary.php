<?php

declare(strict_types=1);

namespace Liberu\Music\ProjectsApi\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class ApiBoundary
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set('music.request_id', (string) Str::uuid());
        $request->headers->set('Accept', 'application/json');
        $response = $next($request);
        $response->headers->set('X-Request-ID', $request->attributes->get('music.request_id'));
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
