<?php

declare(strict_types=1);

namespace Liberu\Music\ProjectsApi\Http\Requests;

final class SaveCheckpointRequest extends SessionCommandRequest
{
    public function rules(): array
    {
        return parent::rules() + ['title' => ['required', 'string', 'max:255'], 'document' => ['required', 'array'],
            'autosave' => ['sometimes', 'boolean:strict'], 'template' => ['sometimes', 'boolean:strict']];
    }
}
