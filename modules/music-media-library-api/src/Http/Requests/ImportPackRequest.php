<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibraryApi\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ImportPackRequest extends FormRequest
{
    public function rules(): array
    {
        return ['manifest' => ['required', 'array:format,format_version,document,checksum'],
            'asset_map' => ['required', 'array', 'max:1000'], 'asset_map.*' => ['required', 'uuid', 'distinct:strict']];
    }

    public function manifest(): string
    {
        return json_encode($this->validated('manifest'), JSON_THROW_ON_ERROR);
    }
}
