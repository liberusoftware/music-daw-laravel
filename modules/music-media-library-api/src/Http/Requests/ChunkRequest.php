<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibraryApi\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use Liberu\Music\MediaLibrary\Support\ArchiveInputLimit;

final class ChunkRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['offset_bytes' => $this->route('offset'), 'chunk_bytes' => strlen($this->bytes()), 'headers' => ['X-Chunk-SHA256' => $this->header('X-Chunk-SHA256')]]);
    }

    public function rules(): array
    {
        $limit = $this->routeIs('music.media.api.archive-input-append') ? ArchiveInputLimit::bytes() : 1073741824;

        return ['offset_bytes' => ['required', 'integer', 'between:0,'.$limit], 'chunk_bytes' => ['integer:strict', 'between:1,4194304'],
            'headers.X-Chunk-SHA256' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D']];
    }

    public function bytes(): string
    {
        return $this->attributes->get('music.raw_chunk');
    }

    public function checksum(): string
    {
        $checksum = $this->validated('headers.X-Chunk-SHA256');
        if (! hash_equals($checksum, hash('sha256', $this->bytes()))) {
            throw ValidationException::withMessages(['headers.X-Chunk-SHA256' => 'The chunk checksum does not match its bytes.']);
        }

        return $checksum;
    }
}
