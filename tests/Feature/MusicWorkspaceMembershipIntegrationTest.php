<?php

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\Foundation\Tenancy\TenantContext;
use Liberu\Music\Projects\Commands\SaveSession;
use Liberu\Music\Projects\Data\SessionDocument;
use Liberu\Music\Projects\Services\ProjectSessions;
use Liberu\Music\ProjectsFilament\Contracts\WorkspaceContext;
use Liberu\Music\ProjectsFilament\MusicProjectsFilamentServiceProvider;
use Liberu\Music\ProjectsFilament\Pages\Sessions;
use Liberu\Music\ProjectsLivewire\Livewire\SessionWorkspace;
use Liberu\Music\ProjectsLivewire\MusicProjectsLivewireServiceProvider;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->app->register(MusicProjectsLivewireServiceProvider::class);
    $this->app->register(MusicProjectsFilamentServiceProvider::class);
    $this->actor = User::factory()->create();
    $owner = User::factory()->create();
    $this->team = Team::factory()->create(['user_id' => $owner->id]);
    $this->team->users()->attach($this->actor, ['role' => 'editor', 'status' => 'active']);
    $this->actor->forceFill(['current_team_id' => $this->team->id])->save();
    setPermissionsTeamId($this->team->id);
    $role = Role::create(['name' => 'super_admin', 'guard_name' => 'web', 'team_id' => $this->team->id]);
    $this->actor->assignRole($role);
    $this->actingAs($this->actor);
    Filament::setCurrentPanel('app');
    $this->context = app(WorkspaceContext::class);
});

it('honors canonical membership status and effective dates before granting panel context', function (string $status, ?int $from, ?int $until, bool $allowed) {
    $this->freezeTime(function () use ($status, $from, $until, $allowed) {
        $this->team->users()->updateExistingPivot($this->actor->id, ['status' => $status,
            'effective_from' => $from === null ? null : now()->addSeconds($from),
            'effective_until' => $until === null ? null : now()->addSeconds($until)]);
        // The general super-admin gate deliberately allows the operation. The
        // selected workspace still requires live foundation membership.
        expect(Gate::forUser($this->actor)->allows('music.projects.list', [(string) $this->team->id, '*']))->toBeTrue()
            ->and($this->context->current($this->actor))->toBe($allowed ? (string) $this->team->id : null)
            ->and(Sessions::canAccess())->toBe($allowed);
    });
})->with([
    'active open membership' => ['active', null, null, true],
    'not yet effective' => ['active', 60, null, false],
    'expired membership' => ['active', null, -1, false],
    'expiry is exclusive' => ['active', null, 0, false],
    'start is inclusive' => ['active', 0, 60, true],
    'current bounded membership' => ['active', -60, 60, true],
    'invitation is not membership' => ['invited', null, null, false],
    'suspended membership' => ['suspended', null, null, false],
]);

it('rechecks the same context instance after suspension removal and restoration without trusting loaded relations', function () {
    expect($this->context->current($this->actor))->toBe((string) $this->team->id);
    $this->actor->load('currentTeam', 'teams');
    $this->team->users()->updateExistingPivot($this->actor->id, ['status' => 'suspended']);
    expect($this->context->current($this->actor))->toBeNull()->and(Sessions::canAccess())->toBeFalse();
    $this->team->users()->updateExistingPivot($this->actor->id, ['status' => 'active']);
    expect($this->context->current($this->actor))->toBe((string) $this->team->id);
    $this->team->users()->detach($this->actor);
    expect($this->context->current($this->actor))->toBeNull()->and(Sessions::canAccess())->toBeFalse();
    $this->team->users()->attach($this->actor, ['role' => 'editor', 'status' => 'active']);
    expect($this->context->current($this->actor))->toBe((string) $this->team->id);
});

it('requires the selected workspace itself to remain active even for its owner', function () {
    $owned = Team::factory()->create(['user_id' => $this->actor->id]);
    TenantContext::set($owned->id);
    expect($this->context->current($this->actor))->toBe((string) $owned->id);
    $owned->update(['status' => 'suspended']);
    expect($this->context->current($this->actor))->toBeNull()->and(Sessions::canAccess())->toBeFalse();
    $owned->update(['status' => 'active']);
    expect($this->context->current($this->actor))->toBe((string) $owned->id);
    $owned->delete();
    expect($this->context->current($this->actor))->toBeNull();
    TenantContext::clear();
});

it('does not fall back to another team when the explicit server tenant is unauthorized or missing', function () {
    $stranger = Team::factory()->create();
    expect($this->context->current($this->actor))->toBe((string) $this->team->id);
    TenantContext::set($stranger->id);
    expect($this->context->current($this->actor))->toBeNull();
    TenantContext::set(0);
    expect($this->context->current($this->actor))->toBeNull();
    TenantContext::clear();
    expect($this->context->current($this->actor))->toBe((string) $this->team->id);
});

it('blocks a mounted workspace after membership revocation and preserves its unsaved draft for explicit retry', function () {
    $component = Livewire::test(SessionWorkspace::class, ['workspace' => (string) $this->team->id, 'scope' => 'filament'])
        ->set('title', 'Authorized checkpoint')->call('save')->assertSet('version', 1);
    $component->set('title', 'Keep my unsaved edit');
    $this->team->users()->detach($this->actor);
    $component->call('save')->assertSee('Access denied')->assertSet('version', 1)->assertSet('title', 'Keep my unsaved edit')
        ->call('newSession')->assertSet('title', 'Keep my unsaved edit')->call('reviewRecovery', 1)->assertSet('recoveryVersion', null);
    expect(DB::table('music_project_revisions')->count())->toBe(1)
        ->and(DB::table('activity_log')->where('subject_type', 'music-session')->count())->toBe(1);
    $this->team->users()->attach($this->actor, ['role' => 'editor', 'status' => 'active']);
    $component->call('save')->assertSet('version', 2)->assertSet('title', 'Keep my unsaved edit');
    expect(DB::table('music_project_revisions')->count())->toBe(2);
});

it('rechecks effective expiry on an existing component and retries only after membership is extended', function () {
    $this->freezeTime(function () {
        $this->team->users()->updateExistingPivot($this->actor->id, ['effective_from' => now(), 'effective_until' => now()->addMinute()]);
        $component = Livewire::test(SessionWorkspace::class, ['workspace' => (string) $this->team->id, 'scope' => 'filament'])
            ->set('title', 'Saved before expiry')->call('save')->assertSet('version', 1)->set('title', 'Unsaved after expiry');
        $this->travel(1)->minutes();
        $component->call('save')->assertSee('Access denied')->assertSet('version', 1)->assertSet('title', 'Unsaved after expiry');
        expect(DB::table('music_project_revisions')->count())->toBe(1);
        $this->team->users()->updateExistingPivot($this->actor->id, ['effective_until' => now()->addMinute()]);
        $component->call('save')->assertSet('version', 2)->assertSet('title', 'Unsaved after expiry');
    });
});

it('keeps membership lookup work bounded as the session navigator fills', function () {
    $capturing = false;
    $membershipQueries = 0;
    DB::listen(function ($query) use (&$capturing, &$membershipQueries) {
        $sql = str_replace([chr(34), chr(96)], '', strtolower($query->sql));
        if ($capturing && (str_contains($sql, 'from teams') || str_contains($sql, 'from team_user'))) {
            $membershipQueries++;
        }
    });
    $counts = [];
    $createdSessions = 0;
    foreach ([1, 10] as $sessionCount) {
        for (; $createdSessions < $sessionCount; $createdSessions++) {
            app(ProjectSessions::class)->save($this->actor, new SaveSession((string) $this->team->id, (string) Str::uuid(), 'Session '.$createdSessions, SessionDocument::empty(), 0, 'initial'));
        }
        $membershipQueries = 0;
        $capturing = true;
        Livewire::test(SessionWorkspace::class, ['workspace' => (string) $this->team->id, 'scope' => 'filament'])->assertSee('Session '.($sessionCount - 1));
        $capturing = false;
        $counts[] = $membershipQueries;
    }
    expect($counts[0])->toBeGreaterThan(0)->and($counts[1])->toBeGreaterThan(0)->toBeLessThanOrEqual($counts[0]);
});

it('does not turn valid membership into a grant of Music operation permissions', function () {
    $this->actor->removeRole('super_admin');
    expect($this->context->current($this->actor))->toBe((string) $this->team->id)
        ->and(Gate::forUser($this->actor)->allows('music.projects.list', [(string) $this->team->id, '*']))->toBeFalse()
        ->and(Sessions::canAccess())->toBeFalse();
    Livewire::test(SessionWorkspace::class, ['workspace' => (string) $this->team->id, 'scope' => 'filament'])->assertForbidden();
    expect(DB::table('music_project_revisions')->count())->toBe(0);
});
