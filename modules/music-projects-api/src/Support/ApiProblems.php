<?php

declare(strict_types=1);

namespace Liberu\Music\ProjectsApi\Support;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\RecordNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use JsonException;
use Liberu\Music\Projects\Exceptions\IdempotencyConflict;
use Liberu\Music\Projects\Exceptions\RevisionConflict;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class ApiProblems
{
    public function render(Throwable $exception, Request $request): ?JsonResponse
    {
        if (! $request->is('api/v1/music-projects/*')) {
            return null;
        }
        $status = match (true) {
            $exception instanceof IdempotencyConflict => 409,
            $exception instanceof RevisionConflict => 412,
            $exception instanceof AuthenticationException => 401,
            $exception instanceof AuthorizationException => 403,
            $exception instanceof RecordNotFoundException => 404,
            $exception instanceof ValidationException => 422,
            $exception instanceof JsonException => 400,
            $exception instanceof HttpExceptionInterface => $exception->getStatusCode(),
            default => 500,
        };
        $errors = [];
        if ($exception instanceof ValidationException) {
            foreach ($exception->errors() as $field => $messages) {
                foreach ($messages as $message) {
                    $errors[] = ['code' => 'invalid_field', 'pointer' => '/'.str_replace('.', '/', $field), 'message' => $message];
                }
            }
        }
        $requestId = $request->attributes->get('music.request_id', (string) Str::uuid());

        return response()->json([
            'type' => 'https://docs.liberusoftware.com/problems/music-projects-'.$status,
            'title' => Response::$statusTexts[$status], 'status' => $status,
            'detail' => match ($status) {
                409 => 'Use a new idempotency key for a different command.',
                412 => 'Reload the current revision and reconcile changes before retrying.',
                428 => 'Supply the quoted expected revision in the If-Match header.',
                default => 'The Music Projects request could not be completed.',
            }, 'instance' => $request->getPathInfo(),
            'request_id' => $requestId, 'errors' => $errors,
        ], $status, ['Content-Type' => 'application/problem+json', 'X-Request-ID' => $requestId, 'Cache-Control' => 'no-store']
            + ($exception instanceof HttpExceptionInterface ? $exception->getHeaders() : []));
    }
}
