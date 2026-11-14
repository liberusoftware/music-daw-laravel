<?php

namespace App\Console\Commands;

use App\Models\User;
use Exception;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Liberu\Music\MediaLibrary\Commands\CollectTrashedAssets;
use Liberu\Music\MediaLibrary\MusicMediaLibraryServiceProvider;
use Liberu\Music\MediaLibrary\Services\AssetGarbageCollection;

#[Signature('music:media:collect-trash
    {workspace : Workspace whose eligible trash may be collected}
    {actor : Existing host user ID whose current permissions apply}
    {--limit=100 : Maximum assets in this page (1-100)}
    {--after= : Last successfully completed asset UUID cursor}
    {--execute : Permanently collect eligible trash; otherwise report only}')]
#[Description('Collect one authorized page of eligible Music trash; defaults to dry run')]
final class CollectMusicTrash extends Command
{
    public function handle(): int
    {
        if ($this->laravel->getProvider(MusicMediaLibraryServiceProvider::class) === null) {
            $this->error('Music Media Library must be enabled before trash collection.');

            return self::FAILURE;
        }
        $input = ['workspace' => $this->argument('workspace'), 'actor' => $this->argument('actor'), 'limit' => $this->option('limit')];
        if ($this->option('after') !== null) {
            $input['after'] = $this->option('after');
        }
        if (Validator::make($input, [
            'workspace' => ['required', 'string', 'max:128'], 'actor' => ['required', 'integer', 'min:1'],
            'limit' => ['required', 'integer', 'between:1,100'], 'after' => ['sometimes', 'required', 'uuid'],
        ])->fails()) {
            $this->error('Invalid collection arguments. Use help music:media:collect-trash for bounds.');

            return self::INVALID;
        }

        try {
            $actor = User::find($input['actor']);
            if ($actor === null) {
                $this->error('The collection actor is unavailable.');

                return self::FAILURE;
            }
            $report = $this->laravel->make(AssetGarbageCollection::class)->collect($actor, new CollectTrashedAssets(
                $input['workspace'], (int) $input['limit'], $input['after'] ?? null, ! $this->option('execute'),
            ));
        } catch (AuthorizationException) {
            $this->error('Trash collection is not authorized for this actor and workspace.');

            return self::FAILURE;
        } catch (Exception $exception) {
            report($exception);
            $this->error('Trash collection failed. Keep the previous cursor and retry after resolving the reported failure.');

            return self::FAILURE;
        }

        $this->line(json_encode($report, JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
