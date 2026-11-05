<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibraryApi\Http\Middleware;

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
        if ($request->isMethod('PUT') || $request->isMethod('DELETE')) {
            abort_if(strlen($request->getContent()) > 8192 || (int) $request->header('Content-Length', '0') > 8192, 413);
            abort_if($request->getContent() !== '' && ! $request->isJson(), 415);
        }
        $response = $next($request);
        $response->headers->set('X-Request-ID', $request->attributes->get('music.request_id'));
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
