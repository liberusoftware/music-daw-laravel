<?php

namespace App\Providers;

use App\Support\AuditedSessionRevisions;
use App\Support\MusicWorkspaceContext;
use App\Support\UploadedSessionMedia;
use App\Support\UploadedSessionRetention;
use App\Support\WorkspaceMediaAuthorizer;
use App\Support\WorkspacePackDistribution;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Support\ServiceProvider;
use Liberu\Foundation\Files\Contracts\MediaAccess;
use Liberu\Music\MediaLibrary\Contracts\MediaAuthorizer;
use Liberu\Music\MediaLibrary\Contracts\PackDistributionPolicy;
use Liberu\Music\MediaLibrary\MusicMediaLibraryServiceProvider;
use Liberu\Music\MediaLibrary\Support\ScopedMediaAccess;
use Liberu\Music\Projects\Contracts\SessionAudit;
use Liberu\Music\Projects\Contracts\SessionMediaRetention;
use Liberu\Music\Projects\Contracts\VerifiedMedia;
use Liberu\Music\Projects\MusicProjectsServiceProvider;
use Liberu\Music\Projects\Services\ProjectSessions;
use Liberu\Music\ProjectsFilament\Contracts\WorkspaceContext;

final class MusicModuleIntegrationProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(WorkspaceContext::class, MusicWorkspaceContext::class);
        $this->app->when(ProjectSessions::class)->needs(SessionAudit::class)->give(AuditedSessionRevisions::class);
    }

    public function boot(): void
    {
        if ($this->app->getProvider(MusicMediaLibraryServiceProvider::class) !== null) {
            $this->app->bind(MediaAuthorizer::class, WorkspaceMediaAuthorizer::class);
            $this->app->bind(PackDistributionPolicy::class, WorkspacePackDistribution::class);
        }

        if ($this->app->getProvider(MusicProjectsServiceProvider::class) === null
            || $this->app->getProvider(MusicMediaLibraryServiceProvider::class) === null) {
            return;
        }
        // Capture the host identity provider before request guards (such as
        // Sanctum) change the active guard without naming a user provider.
        $provider = config('auth.guards.'.config('auth.defaults.guard').'.provider');
        $this->app->when(ScopedMediaAccess::class)->needs(UserProvider::class)->give(fn () => $provider === null ? null : $this->app->make('auth')->createUserProvider($provider));
        $this->app->bind(VerifiedMedia::class, UploadedSessionMedia::class);
        $this->app->when(ProjectSessions::class)->needs(SessionMediaRetention::class)->give(UploadedSessionRetention::class);
        $this->app->when(ProjectSessions::class)->needs(MediaAccess::class)->give(ScopedMediaAccess::class);
    }
}
