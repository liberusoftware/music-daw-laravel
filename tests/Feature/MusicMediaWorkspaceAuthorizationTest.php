<?php

use App\Models\User;
use App\Providers\MusicModuleIntegrationProvider;
use App\Support\WorkspaceMediaAuthorizer;
use App\Support\WorkspacePackDistribution;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Liberu\Foundation\Files\Contracts\MalwareScanner;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\Music\MediaLibrary\Commands\StartUpload;
use Liberu\Music\MediaLibrary\Contracts\MediaAuthorizer;
use Liberu\Music\MediaLibrary\Contracts\PackDistributionPolicy;
use Liberu\Music\MediaLibrary\Data\MediaPack;
use Liberu\Music\MediaLibrary\MusicMediaLibraryServiceProvider;
use Liberu\Music\MediaLibrary\Services\AssetProcessing;
use Liberu\Music\MediaLibrary\Services\MediaUploads;
use Liberu\Music\MediaLibraryApi\MusicMediaLibraryApiServiceProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->app->register(MusicMediaLibraryServiceProvider::class);
    $this->app->register(MusicMediaLibraryApiServiceProvider::class);
    (new MusicModuleIntegrationProvider($this->app))->boot();
    app('router')->getRoutes()->refreshNameLookups();
    $this->artisan('migrate', ['--path' => base_path('modules/music-media-library/database/migrations'), '--realpath' => true])->assertSuccessful();
    $this->actor = User::factory()->create();
    $owner = User::factory()->create();
    $this->team = Team::factory()->create(['user_id' => $owner->id]);
    $this->otherTeam = Team::factory()->create(['user_id' => $owner->id]);
    foreach ([$this->team, $this->otherTeam] as $team) {
        $team->users()->attach($this->actor, ['role' => 'editor', 'status' => 'active']);
    }
    $this->actor->forceFill(['current_team_id' => $this->otherTeam->id])->save();
    $this->permissions = app(PermissionRegistrar::class);
    $this->grant = function (string $ability, ?Team $team = null) {
        $this->permissions->setPermissionsTeamId(($team ?? $this->team)->id);
        $this->actor->givePermissionTo(Permission::findOrCreate('music.media.'.$ability, 'web'));
    };
    ($this->grant)('read');
    ($this->grant)('list');
    $this->permissions->setPermissionsTeamId($this->otherTeam->id);
    $this->access = app(MediaAuthorizer::class);
    $this->workspace = (string) $this->team->id;
    $this->media = (string) Str::uuid();
    $this->base = '/api/v1/music-media/workspaces/'.$this->workspace;
});

it('uses live workspace permissions independently of the selected tenant and restores worker scope', function () {
    expect($this->access)->toBeInstanceOf(WorkspaceMediaAuthorizer::class)
        ->and(app(PackDistributionPolicy::class))->toBeInstanceOf(WorkspacePackDistribution::class);
    ($this->grant)('upload', $this->otherTeam);
    $this->actor->load('roles', 'permissions', 'teams', 'currentTeam');
    $this->permissions->setPermissionsTeamId($this->otherTeam->id);
    $this->access->authorize($this->actor, $this->workspace, $this->media, 'read');
    expect(fn () => $this->access->authorize($this->actor, $this->workspace, $this->media, 'upload'))->toThrow(AuthorizationException::class);
    expect($this->permissions->getPermissionsTeamId())->toBe($this->otherTeam->id);
    $this->access->authorize($this->actor, (string) $this->otherTeam->id, $this->media, 'upload');
    expect(fn () => $this->access->authorize($this->actor, (string) $this->otherTeam->id, $this->media, 'read'))->toThrow(AuthorizationException::class);
    expect($this->actor->relationLoaded('permissions'))->toBeTrue()
        ->and($this->permissions->getPermissionsTeamId())->toBe($this->otherTeam->id);
    $this->permissions->setPermissionsTeamId(null);
    $this->access->authorize($this->actor, $this->workspace, $this->media, 'read');
    expect($this->permissions->getPermissionsTeamId())->toBeNull();
    expect(fn () => $this->access->authorize($this->actor, $this->workspace, $this->media, 'upload'))->toThrow(AuthorizationException::class);
    expect($this->permissions->getPermissionsTeamId())->toBeNull();
});

it('rechecks revoked direct permissions and role grants without trusting loaded models', function () {
    $this->permissions->setPermissionsTeamId($this->team->id);
    $this->actor->load('permissions', 'roles');
    $this->access->authorize($this->actor, $this->workspace, $this->media, 'read');
    $fresh = User::findOrFail($this->actor->id);
    $fresh->revokePermissionTo('music.media.read');
    expect(fn () => $this->access->authorize($this->actor, $this->workspace, $this->media, 'read'))->toThrow(AuthorizationException::class);
    $role = Role::create(['name' => 'sample-reviewer', 'guard_name' => 'web', 'team_id' => $this->team->id]);
    $role->givePermissionTo('music.media.read');
    $fresh->assignRole($role);
    $this->access->authorize($this->actor, $this->workspace, $this->media, 'read');
    $fresh->removeRole($role);
    expect(fn () => $this->access->authorize($this->actor, $this->workspace, $this->media, 'read'))->toThrow(AuthorizationException::class);
});

it('denies suspended invited expired future or removed memberships even for global administrators', function (string $status, ?int $from, ?int $until) {
    $this->permissions->setPermissionsTeamId($this->otherTeam->id);
    $this->actor->assignRole(Role::create(['name' => 'super_admin', 'guard_name' => 'web', 'team_id' => $this->otherTeam->id]));
    expect(Gate::forUser($this->actor)->allows('music.media.read', [$this->workspace, $this->media]))->toBeTrue();
    $this->access->authorize($this->actor, $this->workspace, $this->media, 'upload');
    $this->actor->load('teams');
    $this->team->users()->updateExistingPivot($this->actor->id, ['status' => $status,
        'effective_from' => $from === null ? null : now()->addSeconds($from),
        'effective_until' => $until === null ? null : now()->addSeconds($until)]);
    expect(fn () => $this->access->authorize($this->actor, $this->workspace, $this->media, 'read'))->toThrow(AuthorizationException::class);
    $this->team->users()->detach($this->actor);
    expect(fn () => $this->access->authorize($this->actor, $this->workspace, $this->media, 'read'))->toThrow(AuthorizationException::class);
})->with([
    'suspended' => ['suspended', null, null],
    'invited' => ['invited', null, null],
    'expired' => ['active', null, -1],
    'future' => ['active', 60, null],
]);

it('keeps workspace validity separate from ownership and checks effective membership on each action', function () {
    $owned = Team::factory()->create(['user_id' => $this->actor->id]);
    expect(fn () => $this->access->authorize($this->actor, (string) $owned->id, $this->media, 'read'))->toThrow(AuthorizationException::class);
    ($this->grant)('read', $owned);
    $this->access->authorize($this->actor, (string) $owned->id, $this->media, 'read');
    $owned->update(['status' => 'suspended']);
    expect(fn () => $this->access->authorize($this->actor, (string) $owned->id, $this->media, 'read'))->toThrow(AuthorizationException::class);
    $this->freezeTime(function () {
        $this->team->users()->updateExistingPivot($this->actor->id, ['effective_from' => now(), 'effective_until' => now()->addMinute()]);
        $this->access->authorize($this->actor, $this->workspace, $this->media, 'read');
        $this->travel(1)->minutes();
        expect(fn () => $this->access->authorize($this->actor, $this->workspace, $this->media, 'read'))->toThrow(AuthorizationException::class);
    });
});

it('rejects noncanonical workspace identities and actors without host membership', function () {
    foreach (['studio', '0', '-1', '01', '+1', '1 ', '1e0', '1.0', '9223372036854775808', (string) PHP_INT_MAX] as $workspace) {
        expect(fn () => $this->access->authorize($this->actor, $workspace, $this->media, 'read'))->toThrow(AuthorizationException::class);
    }
    expect(fn () => $this->access->authorize(new GenericUser(['id' => $this->actor->id]), $this->workspace, $this->media, 'read'))->toThrow(AuthorizationException::class);
    config()->set('permission.teams', false);
    expect(fn () => $this->access->authorize($this->actor, $this->workspace, $this->media, 'read'))->toThrow(AuthorizationException::class);
    expect(fn () => $this->access->authorize(new GenericUser(['id' => $this->actor->id]), $this->workspace, $this->media, 'read'))->toThrow(AuthorizationException::class);
});

it('requires an explicit workspace distribution grant in addition to membership and read permission', function () {
    $pack = new MediaPack($this->workspace, (string) Str::uuid(), 1, 'Review', [], []);
    $distribution = app(PackDistributionPolicy::class);
    expect(fn () => $distribution->authorize($this->actor, $pack))->toThrow(AuthorizationException::class);
    ($this->grant)('pack_distribute');
    $distribution->authorize($this->actor, $pack);
    $this->team->users()->detach($this->actor);
    expect(fn () => $distribution->authorize($this->actor, $pack))->toThrow(AuthorizationException::class);
});

it('enforces the workspace boundary through API and canonical verification workers after membership revocation', function () {
    config()->set('music-media.disk', 'host-workspace-policy');
    Storage::fake('host-workspace-policy');
    app()->instance(MalwareScanner::class, Mockery::mock(MalwareScanner::class)->shouldReceive('clean')->andReturnTrue()->getMock());
    ($this->grant)('upload');
    Bus::fake();
    Sanctum::actingAs($this->actor, ['music.media.read', 'music.media.upload']);
    $this->getJson($this->base.'/capabilities')->assertSuccessful();
    $format = pack('vvVVvv', 1, 1, 48000, 96000, 2, 16);
    $data = 'fmt '.pack('V', 16).$format.'data'.pack('V', 4).pack('v*', 0, 1000);
    $bytes = 'RIFF'.pack('V', strlen($data) + 4).'WAVE'.$data;
    $uploads = app(MediaUploads::class);
    $uploads->start($this->actor, new StartUpload($this->workspace, $this->media, 'Take', strlen($bytes), hash('sha256', $bytes)));
    $uploads->append($this->actor, $this->workspace, $this->media, 0, $bytes);
    $task = (string) Str::uuid();
    $this->withHeader('Idempotency-Key', $task)->putJson($this->base.'/verification-tasks/'.$task, ['upload_id' => $this->media])->assertAccepted();
    $this->team->users()->updateExistingPivot($this->actor->id, ['status' => 'suspended']);
    $this->getJson($this->base.'/capabilities')->assertForbidden();
    $this->getJson($this->base.'/uploads/'.$this->media)->assertForbidden();
    expect(fn () => app(AssetProcessing::class)->process($task))->toThrow(AuthorizationException::class);
    expect(DB::table('music_media_uploads')->where('id', $this->media)->value('status'))->toBe('uploading');
    $this->team->users()->updateExistingPivot($this->actor->id, ['status' => 'active']);
    $this->getJson($this->base.'/capabilities')->assertSuccessful();
    $this->withHeader('Idempotency-Key', $task)->putJson($this->base.'/verification-tasks/'.$task, ['upload_id' => $this->media])->assertAccepted();
    app(AssetProcessing::class)->process($task);
    $this->getJson($this->base.'/asset-tasks/'.$task.'/result')->assertSuccessful()->assertJsonPath('data.id', $this->media);
});
