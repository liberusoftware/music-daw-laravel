<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibrary\Support;

use Illuminate\Support\Facades\Validator;

final class ArchiveInputLimit
{
    public static function bytes(): int
    {
        $limit = config('music-media.archive_max_audio_bytes', 1073741824);
        Validator::make(['audio_limit' => $limit], ['audio_limit' => ['required', 'integer:strict', 'between:44,17179869184']])->validate();

        return $limit + 16777216;
    }
}
