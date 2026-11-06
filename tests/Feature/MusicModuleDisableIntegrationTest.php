<?php

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

it('cold boots disabled Music modules without deleting verified recordings revisions retention or audit and reopens them after reenablement', function () {
    $files = new Filesystem();
    $directory = sys_get_temp_dir().'/music-disable-'.Str::random(20);
    $files->makeDirectory($directory);
    $files->makeDirectory($directory.'/views');
    $database = $directory.'/database.sqlite';
    $files->put($database, '');
    $environment = [
        'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_KEY' => (string) config('app.key'),
        'APP_CONFIG_CACHE' => $directory.'/config.php', 'APP_ROUTES_CACHE' => $directory.'/routes.php',
        'VIEW_COMPILED_PATH' => $directory.'/views', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $database, 'DB_URL' => '',
        'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array',
        'BCRYPT_ROUNDS' => '4', 'MODULES_CACHE' => 'false',
        'MODULES_ENABLED' => 'music-projects,music-media-library,music-projects-livewire,music-projects-filament', 'MODULES_DISABLED' => '',
        'MUSIC_LIFECYCLE_STORAGE' => $directory.'/objects',
    ];
    $bootstrap = <<<'CODE'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['music-media.disk' => 'lifecycle', 'filesystems.disks.lifecycle' => ['driver' => 'local', 'root' => getenv('MUSIC_LIFECYCLE_STORAGE')]]);
CODE;
    $run = function (string $code, array $overrides = []) use ($environment, $bootstrap) {
        $process = new Process([PHP_BINARY, '-r', $bootstrap.$code], base_path(), array_replace($environment, $overrides));
        $process->setTimeout(60)->mustRun();

        return json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR);
    };
    try {
        // Verify the isolated connection before running any migrations.
        expect($run("echo json_encode(config('database.connections.sqlite.database'));"))->toBe($database);
        $migration = new Process([PHP_BINARY, 'artisan', 'migrate', '--force', '--no-interaction'], base_path(), $environment);
        $migration->setTimeout(60)->mustRun();
        $saved = $run(<<<'CODE'
$actor = App\Models\User::factory()->create();
foreach (['music.projects.write', 'music.projects.read', 'music.media.upload', 'music.media.read', 'music.media.retain'] as $ability) {
    Illuminate\Support\Facades\Gate::define($ability, fn ($user, $workspace) => $user->is($actor) && $workspace === 'lifecycle-workspace');
}
$app->instance(Liberu\Foundation\Files\Contracts\MalwareScanner::class, new class implements Liberu\Foundation\Files\Contracts\MalwareScanner {
    public function clean(string $path): bool { return true; }
});
$chunks = 'fmt '.pack('V', 16).pack('vvVVvv', 1, 1, 48000, 96000, 2, 16).'data'.pack('V', 4).pack('v*', 0, 16384);
$bytes = 'RIFF'.pack('V', strlen($chunks) + 4).'WAVE'.$chunks;
$assetId = (string) Illuminate\Support\Str::uuid();
$uploads = app(Liberu\Music\MediaLibrary\Services\MediaUploads::class);
$uploads->start($actor, new Liberu\Music\MediaLibrary\Commands\StartUpload('lifecycle-workspace', $assetId, 'Durable take', strlen($bytes), hash('sha256', $bytes)));
$uploads->append($actor, 'lifecycle-workspace', $assetId, 0, $bytes);
$asset = $uploads->complete($actor, 'lifecycle-workspace', $assetId);
$document = Liberu\Music\Projects\Data\SessionDocument::empty()->jsonSerialize();
$document['assets'] = [$asset->reference()];
$document['tracks'] = [['id' => (string) Illuminate\Support\Str::uuid(), 'name' => 'Take', 'kind' => 'audio', 'parent_id' => null, 'media_ids' => [$assetId]]];
$id = (string) Illuminate\Support\Str::uuid();
$revision = app(Liberu\Music\Projects\Services\ProjectSessions::class)->save($actor, new Liberu\Music\Projects\Commands\SaveSession('lifecycle-workspace', $id, 'Keep this session', Liberu\Music\Projects\Data\SessionDocument::fromArray($document), 0, 'initial'));
echo json_encode(['actor_id' => $actor->id, 'project_id' => $id, 'asset_id' => $assetId, 'checksum' => $revision->document->checksum(), 'asset' => $asset->reference(), 'route' => Illuminate\Support\Facades\Route::has('music.projects.livewire.workspace')], JSON_THROW_ON_ERROR);
CODE);
        expect($saved['route'])->toBeTrue();
        $pdo = new PDO('sqlite:'.$database);
        $snapshot = function () use ($pdo, $directory) {
            $blobs = glob($directory.'/objects/music/assets/*/*');
            sort($blobs);

            return [
                'projects' => $pdo->query('SELECT * FROM music_projects ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
                'uploads' => $pdo->query('SELECT * FROM music_media_uploads ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
                'revisions' => $pdo->query('SELECT * FROM music_project_revisions ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
                'retention' => $pdo->query('SELECT * FROM music_media_retention ORDER BY asset_id, reference_hash')->fetchAll(PDO::FETCH_ASSOC),
                'audit' => $pdo->query("SELECT * FROM activity_log WHERE subject_type = 'music-session' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC),
                'blobs' => array_map(fn ($path) => hash_file('sha256', $path), $blobs),
            ];
        };
        $before = $snapshot();
        expect($before['projects'])->toHaveCount(1)->and($before['uploads'])->toHaveCount(1)->and($before['uploads'][0]['status'])->toBe('ready')
            ->and($before['revisions'])->toHaveCount(1)->and($before['retention'])->toHaveCount(1)
            ->and($before['audit'])->toHaveCount(1)->and($before['blobs'])->toBe([$saved['asset']['sha256']]);
        $disabled = ['MODULES_ENABLED' => '', 'MODULES_DISABLED' => 'music-projects,music-media-library,music-projects-livewire,music-projects-filament'];
        $state = $run(<<<'CODE'
echo json_encode(['projects' => $app->getProvider(Liberu\Music\Projects\MusicProjectsServiceProvider::class) !== null,
    'media' => $app->getProvider(Liberu\Music\MediaLibrary\MusicMediaLibraryServiceProvider::class) !== null,
    'workspace_route' => Illuminate\Support\Facades\Route::has('music.projects.livewire.workspace')], JSON_THROW_ON_ERROR);
CODE, $disabled);
        expect($state)->toBe(['projects' => false, 'media' => false, 'workspace_route' => false])->and($snapshot())->toBe($before);
        $invalid = new Process([PHP_BINARY, '-r', $bootstrap], base_path(), array_replace($environment, ['MODULES_DISABLED' => 'music-projects']));
        $invalid->setTimeout(60)->run();
        expect($invalid->isSuccessful())->toBeFalse()->and($invalid->getErrorOutput().$invalid->getOutput())->toContain('requires enabled package')
            ->and($snapshot())->toBe($before);
        $reopened = $run(<<<'CODE'
$actor = App\Models\User::findOrFail((int) getenv('MUSIC_ACTOR_ID'));
foreach (['music.projects.read', 'music.media.read'] as $ability) {
    Illuminate\Support\Facades\Gate::define($ability, fn ($user, $workspace) => $user->is($actor) && $workspace === 'lifecycle-workspace');
}
$revision = app(Liberu\Music\Projects\Services\ProjectSessions::class)->get($actor, 'lifecycle-workspace', getenv('MUSIC_PROJECT_ID'));
echo json_encode(['version' => $revision->version, 'title' => $revision->title, 'checksum' => $revision->document->checksum(), 'assets' => $revision->document->assets(), 'route' => Illuminate\Support\Facades\Route::has('music.projects.livewire.workspace')], JSON_THROW_ON_ERROR);
CODE, ['MUSIC_ACTOR_ID' => (string) $saved['actor_id'], 'MUSIC_PROJECT_ID' => $saved['project_id']]);
        expect($reopened)->toBe(['version' => 1, 'title' => 'Keep this session', 'checksum' => $saved['checksum'], 'assets' => [$saved['asset']], 'route' => true])
            ->and($snapshot())->toBe($before);
    } finally {
        $files->deleteDirectory($directory);
    }
});
