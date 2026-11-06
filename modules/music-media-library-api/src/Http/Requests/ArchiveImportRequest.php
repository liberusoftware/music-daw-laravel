<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibraryApi\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;

final class ArchiveImportRequest extends FormRequest
{
    public function rules(): array
    {
        return ['input_id' => ['required', 'uuid'], 'asset_map' => ['required', 'array', 'max:1000'],
            'asset_map.*' => ['required', 'uuid', 'distinct:strict']];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_keys($this->array('asset_map')) as $source) {
                if (! Str::isUuid((string) $source)) {
                    $validator->errors()->add('asset_map', 'Every source asset key must be a UUID.');
                    break;
                }
            }
        }];
    }
}
