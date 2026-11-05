<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibraryApi\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UploadRequest extends FormRequest
{
    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:255'], 'bytes' => ['required', 'integer:strict', 'between:44,1073741824'],
            'sha256' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'], 'provenance' => ['sometimes', 'array:source,license,attribution'], 'provenance.*' => ['string', 'max:2048']];
    }
}
