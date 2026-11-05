<?php

return [
    'user_provider' => env('MUSIC_MEDIA_USER_PROVIDER'),
    'max_reserved_bytes' => env('MUSIC_MEDIA_MAX_RESERVED_BYTES'),
    'disk' => env('MUSIC_MEDIA_DISK', 'local'),
    'archive_max_audio_bytes' => (int) env('MUSIC_MEDIA_ARCHIVE_MAX_AUDIO_BYTES', 1073741824),
];
