<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibraryApi\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class CommandHeaders
{
    public function key(Request $request, ?string $expected = null): string
    {
        $key = $request->header('Idempotency-Key');
        abort_if($key === null || $key === '', 428);
        Validator::make(['headers' => ['Idempotency-Key' => $key]], ['headers.Idempotency-Key' => ['required', 'string', 'regex:/^[\x21-\x7e]{1,128}$/D']])->validate();
        if ($expected !== null && ! hash_equals($expected, $key)) {
            throw ValidationException::withMessages(['headers.Idempotency-Key' => 'Use the stable operation identity documented for this endpoint.']);
        }

        return $key;
    }

    public function version(Request $request): int
    {
        $value = $request->header('If-Match');
        abort_if($value === null || $value === '', 428);
        Validator::make(['headers' => ['If-Match' => $value]], ['headers.If-Match' => ['required', 'string', 'regex:/^"(0|[1-9][0-9]{0,18})"$/D']])->validate();
        $digits = substr($value, 1, -1);
        Validator::make(['headers' => ['If-Match' => $digits]], ['headers.If-Match' => ['integer', 'between:0,'.(PHP_INT_MAX - 1)]])->validate();

        return (int) $digits;
    }
}
