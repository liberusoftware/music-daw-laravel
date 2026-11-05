<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibraryApi\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SaveOfflinePinRequest extends FormRequest
{
    public function rules(): array
    {
        return ['asset_ids' => ['present', 'array', 'list', 'max:10000'], 'asset_ids.*' => ['required', 'uuid', 'distinct:strict'],
            'expires_at' => ['sometimes', 'nullable', 'date_format:Y-m-d\TH:i:s\Z']];
    }
}
