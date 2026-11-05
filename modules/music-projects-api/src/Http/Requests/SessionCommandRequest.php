<?php

declare(strict_types=1);

namespace Liberu\Music\ProjectsApi\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Validator;

abstract class SessionCommandRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        abort_unless($this->hasHeader('If-Match'), 428);
        $this->merge(['headers' => ['If-Match' => $this->header('If-Match'), 'Idempotency-Key' => $this->header('Idempotency-Key')]]);
    }

    public function rules(): array
    {
        return ['headers' => ['required', 'array:If-Match,Idempotency-Key'],
            'headers.If-Match' => ['required', 'string', 'regex:/^"(0|[1-9][0-9]{0,18})"$/D'],
            'headers.Idempotency-Key' => ['required', 'string', 'regex:/^[\x21-\x7e]{1,128}$/D']];
    }

    public function expectedVersion(): int
    {
        $version = substr($this->validated('headers.If-Match'), 1, -1);
        Validator::make(['headers' => ['If-Match' => $version]], ['headers.If-Match' => ['integer', 'between:0,'.(PHP_INT_MAX - 1)]])->validate();

        return (int) $version;
    }

    public function commandKey(): string
    {
        return $this->validated('headers.Idempotency-Key');
    }
}
