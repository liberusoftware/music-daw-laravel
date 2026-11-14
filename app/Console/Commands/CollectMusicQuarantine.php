<?php

namespace App\Console\Commands;

use App\Models\User;
use Exception;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Liberu\Music\MediaLibrary\Commands\CollectCancelledUploads;
use Liberu\Music\MediaLibrary\MusicMediaLibraryServiceProvider;
use Liberu\Music\MediaLibrary\Services\QuarantineCleanup;

#[Signature('music:media:collect-quarantine
    {workspace : Workspace to authorize and collect}
    {actor : Existing host user ID whose current permissions apply}
    {--limit=100 : Maximum chunks in this page (1-100)}
    {--after=0 : Last successfully completed chunk cursor}
    {--grace-hours=24 : Minimum cancellation age (24-8760 hours)}
    {--execute : Delete eligible cancelled chunks; otherwise report only}')]
#[Description('Collect one authorized page of cancelled Music upload chunks; defaults to dry run')]
final class CollectMusicQuarantine extends Command
{
    public function handle(): int
    {
        if ($this->laravel->getProvider(MusicMediaLibraryServiceProvider::class) === null) {
            $this->error('Music Media Library must be enabled before collection.');

            return self::FAILURE;
        }

        $input = ['workspace' => $this->argument('workspace'), 'actor' => $this->argument('actor'),
            'limit' => $this->option('limit'), 'after' => $this->option('after'), 'grace' => $this->option('grace-hours')];
        $validator = Validator::make($input, [
            'workspace' => ['required', 'string', 'max:128'], 'actor' => ['required', 'integer', 'min:1'],
            'limit' => ['required', 'integer', 'between:1,100'], 'after' => ['required', 'integer', 'min:0'],
            'grace' => ['required', 'integer', 'between:24,8760'],
        ]);
        if ($validator->fails()) {
            $this->error('Invalid collection arguments. Use help music:media:collect-quarantine for bounds.');

            return self::INVALID;
        }

        try {
            $actor = User::find($input['actor']);
            if ($actor === null) {
                $this->error('The collection actor is unavailable.');

                return self::FAILURE;
            }
            $report = $this->laravel->make(QuarantineCleanup::class)->collect($actor, new CollectCancelledUploads(
                $input['workspace'], (int) $input['grace'], (int) $input['limit'], (int) $input['after'], ! $this->option('execute'),
            ));
        } catch (AuthorizationException) {
            $this->error('Collection is not authorized for this actor and workspace.');

            return self::FAILURE;
        } catch (Exception $exception) {
            report($exception);
            $this->error('Collection failed. Keep the previous cursor and retry after resolving the reported failure.');

            return self::FAILURE;
        }

        $this->line(json_encode($report, JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
