<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibraryApi\Support;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\RecordNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use JsonException;
use Liberu\Music\MediaLibrary\Exceptions\InvalidAudio;
use Liberu\Music\MediaLibrary\Exceptions\MetadataCommandConflict;
use Liberu\Music\MediaLibrary\Exceptions\MetadataConflict;
use Liberu\Music\MediaLibrary\Exceptions\UploadBudgetExceeded;
use Liberu\Music\MediaLibrary\Exceptions\UploadConflict;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class ApiProblems
{
    public function render(Throwable $exception, Request $request): ?JsonResponse
    {
        if (! $request->is('api/v1/music-media/*')) {
            return null;
        }
        $status = match (true) {
            $exception instanceof AuthenticationException => 401, $exception instanceof AuthorizationException => 403,
            $exception instanceof RecordNotFoundException => 404, $exception instanceof UploadConflict, $exception instanceof MetadataCommandConflict => 409,
            $exception instanceof MetadataConflict => 412, $exception instanceof UploadBudgetExceeded => 429,
            $exception instanceof ValidationException, $exception instanceof InvalidAudio => 422,
            $exception instanceof JsonException => 400, $exception instanceof HttpExceptionInterface => $exception->getStatusCode(), default => 500,
        };
        $errors = [];
        if ($exception instanceof ValidationException) {
            foreach ($exception->errors() as $field => $messages) {
                $field = match ($field) {
                    'upload' => $request->route('asset') === null ? 'upload_id' : 'replacement_upload_id',
                    'replacement' => 'replacement_upload_id',
                    default => $field,
                };
                foreach ($messages as $message) {
                    $errors[] = ['code' => 'invalid_field', 'pointer' => '/'.str_replace('.', '/', $field), 'message' => $message];
                }
            }
        }
        $requestId = $request->attributes->get('music.request_id', (string) Str::uuid());

        return response()->json(['type' => 'https://docs.liberusoftware.com/problems/music-media-'.match (true) {
            $exception instanceof MetadataCommandConflict => 'metadata-command-conflict',
            $exception instanceof UploadBudgetExceeded => 'upload-budget-exceeded',
            default => $status,
        },
            'title' => Response::$statusTexts[$status] ?? 'Request failed', 'status' => $status,
            'detail' => $status === 428 ? 'Supply the required Idempotency-Key and quoted If-Match precondition headers.' : 'The Music Media request could not be completed.',
            'instance' => $request->getPathInfo(), 'request_id' => $requestId, 'errors' => $errors], $status,
            ['Content-Type' => 'application/problem+json', 'Cache-Control' => 'no-store', 'X-Request-ID' => $requestId]
                + ($exception instanceof HttpExceptionInterface ? $exception->getHeaders() : []));
    }
}
