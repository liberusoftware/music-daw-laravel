<?php

namespace Liberu\Music\MediaLibrary\Tests\Fixtures;

class TruncatedAudioFilter extends \php_user_filter
{
    private int $remaining = 40;

    public function filter($in, $out, &$consumed, bool $closing): int
    {
        while ($bucket = stream_bucket_make_writeable($in)) {
            $consumed += $bucket->datalen;
            $bucket->data = substr($bucket->data, 0, $this->remaining);
            $bucket->datalen = strlen($bucket->data);
            $this->remaining -= $bucket->datalen;
            stream_bucket_append($out, $bucket);
        }

        return PSFS_PASS_ON;
    }
}
