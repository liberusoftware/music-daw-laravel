<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibraryApi\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ReadPackRequest extends FormRequest
{
    public function rules(): array
    {
        $version = ['sometimes', 'integer', 'between:1,'.PHP_INT_MAX, 'regex:/^[1-9][0-9]{0,18}$/D'];

        return ['version' => $version, 'page' => ['sometimes', 'array:limit,before'],
            'page.limit' => ['sometimes', 'integer', 'between:1,100'], 'page.before' => $version];
    }

    public function version(): ?int
    {
        $value = $this->validated('version');

        return $value === null ? null : (int) $value;
    }
}
