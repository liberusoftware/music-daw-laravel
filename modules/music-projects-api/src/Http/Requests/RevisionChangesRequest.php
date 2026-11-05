<?php

declare(strict_types=1);

namespace Liberu\Music\ProjectsApi\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class RevisionChangesRequest extends FormRequest
{
    public function rules(): array
    {
        return ['page' => ['sometimes', 'array:size,after'], 'page.size' => ['sometimes', 'integer', 'between:1,100'],
            'page.after' => ['sometimes', 'required', 'string', 'max:2048']];
    }
}
