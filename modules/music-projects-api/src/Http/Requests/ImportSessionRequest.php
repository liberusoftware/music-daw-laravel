<?php

declare(strict_types=1);

namespace Liberu\Music\ProjectsApi\Http\Requests;

use Illuminate\Validation\Rule;

final class ImportSessionRequest extends SessionCommandRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        $rules['headers.If-Match'][] = Rule::in(['"0"']);

        return $rules + ['manifest' => ['required', 'string', 'max:17825792']];
    }
}
