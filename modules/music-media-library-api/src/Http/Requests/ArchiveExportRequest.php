<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibraryApi\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ArchiveExportRequest extends FormRequest
{
    public function rules(): array
    {
        return ['version' => ['required', 'integer:strict', 'min:1']];
    }
}
