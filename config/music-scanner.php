<?php

return [
    'driver' => env('MUSIC_MEDIA_SCANNER', 'reject'),
    'binary' => env('MUSIC_CLAMSCAN_BINARY', '/usr/bin/clamscan'),
    'database' => env('MUSIC_CLAMAV_DATABASE', '/var/lib/clamav'),
    'timeout_seconds' => env('MUSIC_CLAMAV_TIMEOUT_SECONDS', 20),
    'max_file_bytes' => env('MUSIC_CLAMAV_MAX_FILE_BYTES', 1090519040),
    'max_scan_bytes' => env('MUSIC_CLAMAV_MAX_SCAN_BYTES', 4294967296),
    'max_signature_age_days' => env('MUSIC_CLAMAV_MAX_SIGNATURE_AGE_DAYS', 7),
];
