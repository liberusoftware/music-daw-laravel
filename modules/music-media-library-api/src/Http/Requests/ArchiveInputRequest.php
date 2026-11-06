<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibraryApi\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Liberu\Music\MediaLibrary\Support\ArchiveInputLimit;

final class ArchiveInputRequest extends FormRequest
{
    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:255'], 'bytes' => ['required', 'integer:strict', 'between:44,'.ArchiveInputLimit::bytes()],
            'sha256' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'], 'provenance' => ['sometimes', 'array:source,license,attribution'], 'provenance.*' => ['string', 'max:2048']];
    }
}
