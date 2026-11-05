<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibraryApi\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class TrashRequest extends FormRequest
{
    public function rules(): array
    {
        return ['asset_ids' => ['required', 'array', 'list', 'max:100'], 'asset_ids.*' => ['required', 'uuid', 'distinct:strict'],
            'grace_hours' => ['sometimes', 'integer:strict', 'between:24,8760']];
    }
}
