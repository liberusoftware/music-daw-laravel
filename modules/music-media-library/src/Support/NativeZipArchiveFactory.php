<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibrary\Support;

use Illuminate\Validation\ValidationException;
use Liberu\Music\MediaLibrary\Contracts\ZipArchiveFactory;
use ZipArchive;

final readonly class NativeZipArchiveFactory implements ZipArchiveFactory
{
    public function __construct(private ZipDirectoryLimits $limits) {}

    public function open(string $path, int $flags): ZipArchive
    {
        if (($flags & ZipArchive::RDONLY) !== 0) {
            $this->limits->validate($path);
        }
        $zip = new ZipArchive();
        if ($zip->open($path, $flags) !== true) {
            throw ValidationException::withMessages(['archive' => 'The ZIP archive could not be opened.']);
        }

        return $zip;
    }
}
