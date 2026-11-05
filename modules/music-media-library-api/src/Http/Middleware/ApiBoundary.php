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
            $binary = $request->routeIs('music.media.api.append');
            $limit = $binary ? 4194304 : 8192;
            abort_if((int) $request->header('Content-Length', '0') > $limit, 413);
            $stream = $request->getContent(true);
            abort_unless(is_resource($stream), 400);
            $body = stream_get_contents($stream, $limit + 1);
            abort_if($body === false, 400);
            abort_if(strlen($body) > $limit, 413);
            if ($binary) {
                $request->attributes->set('music.raw_chunk', $body);
                abort_unless(strtolower(trim(explode(';', $request->header('Content-Type', ''))[0])) === 'application/octet-stream', 415);
            } else {
                abort_if($body !== '' && ! $request->isJson(), 415);
            }
        }
        $response = $next($request);
        $response->headers->set('X-Request-ID', $request->attributes->get('music.request_id'));
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
