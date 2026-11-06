<?php

use App\Models\User;
use App\Providers\MusicModuleIntegrationProvider;
use App\Support\ClamAvScanner;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Process\Factory;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Liberu\Foundation\Files\Contracts\MalwareScanner;
use Liberu\Foundation\Files\Support\RejectingMalwareScanner;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\Music\MediaLibrary\Commands\StartUpload;
use Liberu\Music\MediaLibrary\Exceptions\InvalidAudio;
use Liberu\Music\MediaLibrary\MusicMediaLibraryServiceProvider;
use Liberu\Music\MediaLibrary\Services\MediaUploads;
use Spatie\Permission\Models\Permission;

it('keeps unspecified or invalid scanner drivers rejecting and enables the configured ClamAV adapter', function () {
    $this->app->register(MusicMediaLibraryServiceProvider::class);
    $provider = new MusicModuleIntegrationProvider($this->app);
    foreach (['reject', 'misspelled'] as $driver) {
        config()->set('music-scanner.driver', $driver);
        $provider->boot();
        expect(app(MalwareScanner::class))->toBeInstanceOf(RejectingMalwareScanner::class)
            ->and(app(MalwareScanner::class)->clean(__FILE__))->toBeFalse();
    }
    config()->set('music-scanner.driver', 'clamav');
    $provider->boot();
    expect(app(MalwareScanner::class))->toBeInstanceOf(ClamAvScanner::class);
});

it('uses the real ClamAV binary to verify audio and reject a test signature inside a ZIP', function () {
    $files = new Filesystem();
    $directory = sys_get_temp_dir().'/music-clamav-'.Str::random(20);
    $files->makeDirectory($directory, 0700);
    // A deterministic test database avoids downloading production signatures in CI.
    // Production must use the maintained official ClamAV signature database.
    $eicar = 'X5O!P%@AP[4\\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*';
    $files->put($directory.'/daily.hdb', md5($eicar).':'.strlen($eicar).":Music.Test.Eicar\n");
    try {
        expect(hash_file('sha256', base_path('tests/Fixtures/ClamAv/test-1.cvd')))->toBe('a39c001ff5a02af09afc204f5710b58290dc68bdc76b6343011188179bf4d7cd');
        $files->copy(base_path('tests/Fixtures/ClamAv/test-1.cvd'), $directory.'/test.cvd');
        $library = getenv('MUSIC_CLAMAV_TEST_FAKETIME_LIBRARY') ?: '/usr/lib/x86_64-linux-gnu/faketime/libfaketime.so.1';
        expect(is_file($library))->toBeTrue('Install libfaketime for real scanner integration tests.');
        $processes = new class($library) extends Factory
        {
            public string $date = '2021-06-23 12:00:00';

            public function __construct(private readonly string $library) {}

            public function newPendingProcess(): PendingProcess
            {
                return parent::newPendingProcess()->env(['LD_PRELOAD' => $this->library, 'FAKETIME' => $this->date, 'FAKETIME_DONT_FAKE_MONOTONIC' => '1']);
            }
        };
        app()->instance(Factory::class, $processes);
        config()->set('music-scanner.driver', 'clamav');
        config()->set('music-scanner.database', $directory);
        $this->app->register(MusicMediaLibraryServiceProvider::class);
        (new MusicModuleIntegrationProvider($this->app))->boot();
        $this->artisan('migrate', ['--path' => base_path('modules/music-media-library/database/migrations'), '--realpath' => true])->assertSuccessful();
        config()->set('music-media.disk', 'real-clamav-music');
        Storage::fake('real-clamav-music');
        $actor = User::factory()->create();
        $team = Team::factory()->create(['user_id' => $actor->id]);
        setPermissionsTeamId($team->id);
        foreach (['upload', 'read'] as $operation) {
            $actor->givePermissionTo(Permission::findOrCreate('music.media.'.$operation, 'web'));
        }
        $format = pack('vvVVvv', 1, 1, 48000, 96000, 2, 16);
        $data = 'fmt '.pack('V', 16).$format.'data'.pack('V', 4).pack('v*', 0, 1000);
        $bytes = 'RIFF'.pack('V', strlen($data) + 4).'WAVE'.$data;
        $uploads = app(MediaUploads::class);
        $id = (string) Str::uuid();
        $uploads->start($actor, new StartUpload((string) $team->id, $id, 'Clean take', strlen($bytes), hash('sha256', $bytes)));
        $uploads->append($actor, (string) $team->id, $id, 0, $bytes);
        expect($uploads->complete($actor, (string) $team->id, $id)->reference()['sha256'])->toBe(hash('sha256', $bytes));
        $zip = new ZipArchive();
        $zip->open($directory.'/test.zip', ZipArchive::CREATE);
        $zip->addFromString('test.com', $eicar);
        $zip->close();
        $archive = file_get_contents($directory.'/test.zip');
        $input = (string) Str::uuid();
        $uploads->start($actor, new StartUpload((string) $team->id, $input, 'Test archive', strlen($archive), hash('sha256', $archive), purpose: 'archive'));
        $uploads->append($actor, (string) $team->id, $input, 0, $archive);
        expect(fn () => $uploads->openArchive($actor, (string) $team->id, $input))->toThrow(InvalidAudio::class);
        $files->put($directory.'/test.com', $eicar);
        expect(app(MalwareScanner::class)->clean($directory.'/test.com'))->toBeFalse();
        $files->put($directory.'/clean.wav', $bytes);
        expect(app(MalwareScanner::class)->clean($directory.'/clean.wav'))->toBeTrue();
        $processes->date = '2021-07-02 12:00:00';
        expect(app(MalwareScanner::class)->clean($directory.'/clean.wav'))->toBeFalse();
        $processes->date = '2021-06-23 12:00:00';
        expect(app(MalwareScanner::class)->clean($directory.'/clean.wav'))->toBeTrue();
        $files->put($directory.'/broken.hdb', 'not a valid signature');
        expect(app(MalwareScanner::class)->clean($directory.'/clean.wav'))->toBeFalse();
    } finally {
        $files->deleteDirectory($directory);
    }
});
