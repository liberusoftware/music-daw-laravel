<?php

use App\Support\ClamAvScanner;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ProcessTimedOutException as SymfonyTimeout;
use Symfony\Component\Process\Process as SymfonyProcess;

beforeEach(function () {
    config()->set('music-scanner.binary', PHP_BINARY);
    $this->database = sys_get_temp_dir().'/scanner-unit-'.Str::random(20);
    mkdir($this->database, 0700);
    file_put_contents($this->database.'/fixture.cvd', 'Process fake only');
    config()->set('music-scanner.database', $this->database);
    $this->file = tmpfile();
    $this->path = stream_get_meta_data($this->file)['uri'];
    $this->bytes = str_repeat('Test audio bytes ', 4);
    fwrite($this->file, $this->bytes);
    Process::preventStrayProcesses();
});

afterEach(function () {
    fclose($this->file);
    (new Filesystem())->deleteDirectory($this->database);
});

it('streams the complete file with fixed safe flags and closes its private reader', function () {
    config()->set('music-scanner.timeout_seconds', '20');
    $reader = null;
    Process::fake(function (PendingProcess $process) use (&$reader) {
        $reader = $process->input;
        expect(stream_get_contents($reader))->toBe($this->bytes)
            ->and($process->timeout)->toBe(20);

        return Process::result(output: "stdin: OK\n");
    });
    expect(app(ClamAvScanner::class)->clean($this->path))->toBeTrue()->and(is_resource($reader))->toBeFalse()
        ->and(is_resource($this->file))->toBeTrue();
    Process::assertRan(function (PendingProcess $process) {
        expect($process->command)->toBe([PHP_BINARY, '--stdout', '--no-summary', '--alert-exceeds-max=yes', '--alert-encrypted=yes',
            '--max-filesize=1090519040', '--max-scansize=4294967296', '--max-scantime=0', '--max-recursion=16', '--max-files=2048',
            '--fail-if-cvd-older-than=7', '--database='.$this->database, '-']);
        expect($process->command)->not->toContain($this->path, '--remove', '--move');

        return true;
    });
});

it('rejects detections scanner errors ambiguous receipts and incomplete input consumption', function (int $exit, string $output, bool $consume) {
    $reader = null;
    Process::fake(function (PendingProcess $process) use ($exit, $output, $consume, &$reader) {
        $reader = $process->input;
        if ($consume) {
            stream_get_contents($reader);
        }

        return Process::result(output: $output, errorOutput: '/private scanner diagnostics', exitCode: $exit);
    });
    expect(app(ClamAvScanner::class)->clean($this->path))->toBeFalse()->and(is_resource($reader))->toBeFalse();
})->with([
    'detected' => [1, 'stdin: Malware FOUND', true],
    'engine failure' => [2, 'stdin: OK', true],
    'missing result' => [0, '', true],
    'ambiguous result' => [0, "stdin: OK\nstdin: ERROR", true],
    'input not consumed' => [0, 'stdin: OK', false],
]);

it('fails closed on process startup faults and timeouts while releasing its input', function (bool $timeout) {
    $reader = null;
    Process::fake(function (PendingProcess $process) use ($timeout, &$reader) {
        $reader = $process->input;
        if ($timeout) {
            $native = new SymfonyProcess([PHP_BINARY]);
            $native->setTimeout(20);
            throw new ProcessTimedOutException(new SymfonyTimeout($native, SymfonyTimeout::TYPE_GENERAL), Process::result());
        }
        throw new RuntimeException('/private executable could not start');
    });
    expect(app(ClamAvScanner::class)->clean($this->path))->toBeFalse()->and(is_resource($reader))->toBeFalse();
})->with([false, true]);

it('rejects invalid scanner settings before creating a child process', function (string $key, mixed $value) {
    Process::fake();
    config()->set('music-scanner.'.$key, $value);
    expect(app(ClamAvScanner::class)->clean($this->path))->toBeFalse();
    Process::assertNothingRan();
})->with([
    ['binary', []], ['binary', '/missing/clamscan'], ['binary', __FILE__],
    ['database', []], ['database', '/missing/database'], ['database', __FILE__],
    ['timeout_seconds', true], ['timeout_seconds', 0], ['timeout_seconds', 46], ['timeout_seconds', 'bad'],
    ['max_file_bytes', 43], ['max_file_bytes', 2147483648], ['max_file_bytes', 3.5],
    ['max_scan_bytes', 43], ['max_scan_bytes', 17179869185], ['max_scan_bytes', 1048576],
    ['max_signature_age_days', 0], ['max_signature_age_days', 31], ['max_signature_age_days', null],
]);

it('rejects missing empty oversized and non-file inputs without invoking the scanner', function () {
    Process::fake();
    $scanner = app(ClamAvScanner::class);
    expect($scanner->clean('/missing/private/audio'))->toBeFalse()
        ->and($scanner->clean(sys_get_temp_dir()))->toBeFalse();
    ftruncate($this->file, 0);
    expect($scanner->clean($this->path))->toBeFalse();
    ftruncate($this->file, 2147483648);
    expect($scanner->clean($this->path))->toBeFalse();
    Process::assertNothingRan();
});

it('requires a readable CVD or CLD database rather than accepting bare custom signatures', function () {
    Process::fake();
    unlink($this->database.'/fixture.cvd');
    file_put_contents($this->database.'/custom.hdb', 'No timestamped database');
    expect(app(ClamAvScanner::class)->clean($this->path))->toBeFalse();
    Process::assertNothingRan();
});
