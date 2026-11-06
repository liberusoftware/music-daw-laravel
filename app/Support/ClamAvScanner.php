<?php

namespace App\Support;

use DirectoryIterator;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Process\Factory;
use Liberu\Foundation\Files\Contracts\MalwareScanner;
use RuntimeException;
use Throwable;

final readonly class ClamAvScanner implements MalwareScanner
{
    public function __construct(private Repository $config, private Factory $processes) {}

    public function clean(string $path): bool
    {
        $input = null;
        try {
            $binary = $this->config->get('music-scanner.binary');
            $database = $this->config->get('music-scanner.database');
            $timeout = $this->integer('timeout_seconds', 1, 45);
            // ClamAV cannot inspect individual files at or above 2 GiB.
            $fileLimit = $this->integer('max_file_bytes', 44, 2147483647);
            $scanLimit = $this->integer('max_scan_bytes', 44, 17179869184);
            $age = $this->integer('max_signature_age_days', 1, 30);
            if (! is_string($binary) || ! is_file($binary) || ! is_executable($binary)
                || ! is_string($database) || ! $this->databaseReady($database)
                || $timeout === null || $fileLimit === null || $scanLimit === null || $age === null || $scanLimit < $fileLimit) {
                return false;
            }
            $resolved = realpath($path);
            if ($resolved === false || ! is_file($resolved) || ! is_readable($resolved)) {
                return false;
            }
            $input = fopen($resolved, 'rb') ?: throw new RuntimeException('Scanner input is unavailable.');
            $stat = fstat($input) ?: throw new RuntimeException('Scanner input metadata is unavailable.');
            if ($stat['size'] < 1 || $stat['size'] > $fileLimit) {
                return false;
            }
            $command = [$binary, '--stdout', '--no-summary', '--alert-exceeds-max=yes', '--alert-encrypted=yes',
                '--max-filesize='.$fileLimit, '--max-scansize='.$scanLimit, '--max-scantime=0',
                '--max-recursion=16', '--max-files=2048', '--fail-if-cvd-older-than='.$age, '--database='.$database, '-'];
            $result = $this->processes->newPendingProcess()->timeout($timeout)->input($input)->run($command);

            return $result->successful() && trim($result->output()) === 'stdin: OK' && ftell($input) === $stat['size'];
        } catch (Throwable) {
            // Process errors and timeouts cannot turn unscanned bytes into a
            // clean result. Keep scanner output and private paths internal.
            return false;
        } finally {
            if (is_resource($input)) {
                fclose($input);
            }
        }
    }

    private function databaseReady(string $directory): bool
    {
        if (! is_dir($directory) || ! is_readable($directory)) {
            return false;
        }
        foreach (new DirectoryIterator($directory) as $file) {
            if ($file->isFile() && $file->isReadable() && in_array(strtolower($file->getExtension()), ['cvd', 'cld'], true)) {
                return true;
            }
        }

        return false;
    }

    private function integer(string $key, int $minimum, int $maximum): ?int
    {
        $value = $this->config->get('music-scanner.'.$key);
        if (! is_int($value) && ! is_string($value)) {
            return null;
        }
        $parsed = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $minimum, 'max_range' => $maximum]]);

        return $parsed === false ? null : $parsed;
    }
}
