<?php

declare(strict_types=1);

namespace Liberu\Music\ProjectsFilament\Pages;

use Filament\Facades\Filament;
use Filament\Pages\Page;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Liberu\Music\Projects\Contracts\ProjectAccess;
use Liberu\Music\Projects\MusicProjectsServiceProvider;
use Liberu\Music\ProjectsFilament\Contracts\WorkspaceContext;
use Liberu\Music\ProjectsLivewire\MusicProjectsLivewireServiceProvider;
use Livewire\Attributes\Locked;

final class Sessions extends Page
{
    protected string $view = 'music-projects-filament::pages.sessions';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-musical-note';

    protected static ?string $navigationLabel = 'Sessions';

    protected static string|\UnitEnum|null $navigationGroup = 'Music';

    protected static ?string $slug = 'music/sessions';

    #[Locked]
    public string $workspaceId;

    public static function canAccess(): bool
    {
        $actor = Filament::auth()->user();
        if (! $actor instanceof Authenticatable || app()->getProvider(MusicProjectsServiceProvider::class) === null
            || app()->getProvider(MusicProjectsLivewireServiceProvider::class) === null) {
            return false;
        }
        $workspace = app(WorkspaceContext::class)->current($actor);
        if ($workspace === null || ! preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $workspace)) {
            return false;
        }
        try {
            app(ProjectAccess::class)->authorize($actor, $workspace, '*', 'list');
        } catch (AuthorizationException) {
            return false;
        }

        return true;
    }

    public function mount(): void
    {
        abort_unless(self::canAccess(), 403);
        $this->workspaceId = app(WorkspaceContext::class)->current(Filament::auth()->user());
    }

    public function hydrate(): void
    {
        abort_unless(self::canAccess() && app(WorkspaceContext::class)->current(Filament::auth()->user()) === $this->workspaceId, 403);
    }
}
