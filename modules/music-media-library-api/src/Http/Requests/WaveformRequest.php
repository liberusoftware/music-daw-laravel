<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibraryApi\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

final class WaveformRequest extends FormRequest
{
    public function rules(): array
    {
        return $this->isMethod('PUT') ? ['asset_id' => ['required', 'uuid'], 'sample_frames_per_peak' => ['sometimes', 'integer:strict', 'between:1,1048576']] : [];
    }

    public function requireIdempotency(string $taskId): void
    {
        $key = $this->header('Idempotency-Key');
        abort_if($key === null || $key === '', 428);
        if (! hash_equals($taskId, $key)) {
            throw ValidationException::withMessages(['headers.Idempotency-Key' => 'Use the task UUID as the stable idempotency key.']);
        }
    }
}
