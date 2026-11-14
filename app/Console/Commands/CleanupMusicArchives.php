<?php

namespace App\Console\Commands;

use App\Models\User;
use Exception;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Liberu\Music\MediaLibrary\MusicMediaLibraryServiceProvider;
use Liberu\Music\MediaLibrary\Services\ArchiveExports;

#[Signature('music:media:cleanup-archives
    {workspace : Workspace whose expired ZIP artifacts may be removed}
    {actor : Existing host user ID whose current permissions apply}
    {--limit=100 : Maximum artifacts in this batch (1-100)}
    {--execute : Required to remove eligible private artifacts}')]
#[Description('Remove one authorized batch of expired Music ZIP artifacts; requires --execute')]
final class CleanupMusicArchives extends Command
{
    public function handle(): int
    {
        if ($this->laravel->getProvider(MusicMediaLibraryServiceProvider::class) === null) {
            $this->error('Music Media Library must be enabled before archive cleanup.');

            return self::FAILURE;
        }
        if (! $this->option('execute')) {
            $this->error('Archive cleanup requires --execute. Core does not provide an archive cleanup dry run.');

            return self::INVALID;
        }
        $input = ['workspace' => $this->argument('workspace'), 'actor' => $this->argument('actor'), 'limit' => $this->option('limit')];
        if (Validator::make($input, [
            'workspace' => ['required', 'string', 'max:128'], 'actor' => ['required', 'integer', 'min:1'],
            'limit' => ['required', 'integer', 'between:1,100'],
        ])->fails()) {
            $this->error('Invalid cleanup arguments. Use help music:media:cleanup-archives for bounds.');

            return self::INVALID;
        }

        try {
            $actor = User::find($input['actor']);
            if ($actor === null) {
                $this->error('The cleanup actor is unavailable.');

                return self::FAILURE;
            }
            $count = $this->laravel->make(ArchiveExports::class)->cleanup($actor, $input['workspace'], (int) $input['limit']);
        } catch (AuthorizationException) {
            $this->error('Archive cleanup is not authorized for this actor and workspace.');

            return self::FAILURE;
        } catch (Exception $exception) {
            report($exception);
            $this->error('Archive cleanup failed. Retry the same batch after resolving the reported failure.');

            return self::FAILURE;
        }

        $this->line(json_encode(['schema_version' => 1, 'workspace_id' => $input['workspace'], 'cleaned_artifacts' => $count], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
