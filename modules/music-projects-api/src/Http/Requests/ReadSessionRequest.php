<?php

declare(strict_types=1);

namespace Liberu\Music\ProjectsApi\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Validator;

final class ReadSessionRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (in_array($this->query('templates'), ['true', 'false'], true)) {
            $this->merge(['templates' => $this->query('templates') === 'true']);
        }
    }

    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'array:size,after'], 'page.size' => ['sometimes', 'integer', 'between:1,100'],
            'page.after' => ['sometimes', 'string', 'max:128'],
            'version' => ['sometimes', 'integer', 'min:1'], 'templates' => ['sometimes', 'boolean'],
            'manifest' => [$this->isMethod('POST') ? 'required' : 'sometimes', 'string', 'max:17825792'],
        ];
    }

    public function cursor(): ?int
    {
        $cursor = $this->validated('page.after');
        if ($cursor === null) {
            return null;
        }
        $decoded = base64_decode($cursor, true);
        Validator::make(['page' => ['after' => $decoded]], ['page.after' => ['required', 'integer', 'min:1']])->validate();

        return (int) $decoded;
    }
}
