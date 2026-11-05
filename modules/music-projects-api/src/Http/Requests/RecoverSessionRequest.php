<?php

declare(strict_types=1);

namespace Liberu\Music\ProjectsApi\Http\Requests;

final class RecoverSessionRequest extends SessionCommandRequest
{
    public function rules(): array
    {
        return parent::rules() + ['source_version' => ['required', 'integer:strict', 'min:1']];
    }
}
