<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibraryApi\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SavePackRequest extends FormRequest
{
    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:255'], 'asset_ids' => ['required', 'array', 'list', 'max:1000'],
            'asset_ids.*' => ['required', 'uuid', 'distinct:strict'], 'rights' => ['required', 'array:source,license,attribution'],
            'rights.source' => ['required', 'string', 'max:2048'], 'rights.license' => ['required', 'string', 'max:2048'],
            'rights.attribution' => ['required', 'string', 'max:2048']];
    }
}
