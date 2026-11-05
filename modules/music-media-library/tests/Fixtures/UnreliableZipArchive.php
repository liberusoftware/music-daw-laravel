<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibrary\Tests\Fixtures;

use ZipArchive;

class UnreliableZipArchive extends ZipArchive
{
    public string $fault = '';

    public string $audio = '';

    public function getFromName(string $name, int $len = 0, int $flags = 0): string|false
    {
        return $this->fault === 'manifest' ? false : parent::getFromName($name, $len, $flags);
    }

    public function getStream(string $name): mixed
    {
        $stream = tmpfile();
        fwrite($stream, $this->fault === 'extra' ? $this->audio.'x' : '');
        rewind($stream);

        return $stream;
    }
}
