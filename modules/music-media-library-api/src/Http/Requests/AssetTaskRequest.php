<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibraryApi\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class AssetTaskRequest extends FormRequest
{
    public function rules(): array
    {
        return [$this->route('asset') === null ? 'upload_id' : 'replacement_upload_id' => ['required', 'uuid']];
    }

    public function uploadId(): string
    {
        return $this->validated($this->route('asset') === null ? 'upload_id' : 'replacement_upload_id');
    }
}
