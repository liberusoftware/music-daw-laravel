<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibrary\Support;

use Illuminate\Validation\ValidationException;
use RuntimeException;

final readonly class ZipDirectoryLimits
{
    public function validate(string $path): void
    {
        $stream = fopen($path, 'rb') ?: throw new RuntimeException('The staged archive cannot be inspected.');
        try {
            $size = fstat($stream)['size'];
            if ($size < 22) {
                throw ValidationException::withMessages(['archive' => 'The ZIP directory end record is truncated.']);
            }
            $length = min($size, 65557);
            $tail = $this->read($stream, $size - $length, $length);
            $positions = [];
            for ($position = $length - 22; $position >= 0; $position--) {
                if (substr($tail, $position, 4) === "PK\x05\x06" && $position + 22 + unpack('v', substr($tail, $position + 20, 2))[1] === $length) {
                    $positions[] = $position;
                }
            }
            if (count($positions) !== 1) {
                throw ValidationException::withMessages(['archive' => 'A single unambiguous ZIP directory end record is required.']);
            }
            $end = unpack('vdisk/vstart/vdisk_entries/ventries/Vbytes/Voffset/vcomment', substr($tail, $positions[0] + 4, 18));
            $boundary = $size - $length + $positions[0];
            $locator = $boundary >= 20 ? $this->read($stream, $boundary - 20, 20) : '';
            if (str_starts_with($locator, "PK\x06\x07") || $end['entries'] === 65535 || $end['bytes'] === 4294967295 || $end['offset'] === 4294967295) {
                if (strlen($locator) !== 20 || ! str_starts_with($locator, "PK\x06\x07")) {
                    throw ValidationException::withMessages(['archive' => 'The ZIP64 directory locator is missing.']);
                }
                $location = unpack('Vdisk/Poffset/Vdisks', substr($locator, 4));
                if ($location['disk'] !== 0 || $location['disks'] !== 1 || $location['offset'] < 0 || $location['offset'] > $boundary - 76) {
                    throw ValidationException::withMessages(['archive' => 'The ZIP64 directory locator is outside its bounded single-disk record.']);
                }
                $record = $this->read($stream, $location['offset'], 56);
                $large = unpack('Psize/vmade/vneeded/Vdisk/Vstart/Pdisk_entries/Pentries/Pbytes/Poffset', substr($record, 4));
                if (! str_starts_with($record, "PK\x06\x06") || $large['size'] < 44 || $large['size'] > 65536 || $large['size'] !== $boundary - 20 - $location['offset'] - 12) {
                    throw ValidationException::withMessages(['archive' => 'The ZIP64 directory record is invalid or oversized.']);
                }
                foreach (['entries' => 65535, 'bytes' => 4294967295, 'offset' => 4294967295] as $field => $sentinel) {
                    if ($end[$field] !== $sentinel && $end[$field] !== $large[$field]) {
                        throw ValidationException::withMessages(['archive' => 'ZIP and ZIP64 directory declarations disagree.']);
                    }
                }
                if ($end['disk'] !== 0 || $end['start'] !== 0 || $end['disk_entries'] !== $end['entries']) {
                    throw ValidationException::withMessages(['archive' => 'Split ZIP directories are not supported.']);
                }
                $end = $large;
                $boundary = $location['offset'];
            }
            if ($end['disk'] !== 0 || $end['start'] !== 0 || $end['disk_entries'] !== $end['entries']
                || $end['entries'] < 2 || $end['entries'] > 1001 || $end['bytes'] < 46 * $end['entries'] || $end['bytes'] > 1048576
                || $end['offset'] < 0 || $end['offset'] > $boundary - $end['bytes']) {
                throw ValidationException::withMessages(['archive' => 'The ZIP directory exceeds its bounded single-disk limits.']);
            }
        } finally {
            fclose($stream);
        }
    }

    /** @param resource $stream */
    private function read(mixed $stream, int $offset, int $length): string
    {
        if ($offset < 0 || fseek($stream, $offset) !== 0) {
            throw ValidationException::withMessages(['archive' => 'A ZIP directory offset is invalid.']);
        }
        $bytes = fread($stream, $length);
        if ($bytes === false || strlen($bytes) !== $length) {
            throw ValidationException::withMessages(['archive' => 'A ZIP directory record is truncated.']);
        }

        return $bytes;
    }
}
