<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibraryApi\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class MetadataRequest extends FormRequest
{
    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:255'], 'tags' => ['present', 'array', 'list', 'max:32'], 'tags.*' => ['required', 'string', 'max:64']];
    }
}
