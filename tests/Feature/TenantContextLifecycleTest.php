<?php

use App\Models\User;
use Filament\Events\TenantSet;
use Filament\Facades\Filament;
use Illuminate\Auth\GenericUser;
use Illuminate\Http\Request;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\Foundation\Tenancy\Http\Middleware\SetPermissionsTeamContext;
use Liberu\Foundation\Tenancy\Http\Middleware\SetTenantContext;
use Liberu\Foundation\Tenancy\Jobs\Concerns\TenantAware;
use Liberu\Foundation\Tenancy\Jobs\Middleware\RestoreTenantContext;
use Liberu\Foundation\Tenancy\Listeners\SwitchTeam;
use Liberu\Foundation\Tenancy\TenantContext;
use Liberu\Foundation\Tenancy\TenantState;

it('captures queued workspace context and clears it after successful and failed jobs', function () {
    Filament::setTenant(null);
    TenantContext::set(41);
    $job = new class()
    {
        use TenantAware;

        public function __construct()
        {
            $this->captureTenant();
        }
    };
    expect($job->tenantId)->toBe(41)->and($job->middleware()[0])->toBeInstanceOf(RestoreTenantContext::class);
    TenantContext::set(99);
    $middleware = $job->middleware()[0];
    $result = $middleware->handle($job, function ($active) use ($job) {
        expect($active)->toBe($job)->and(TenantContext::currentId())->toBe(41);

        return 'render-finished';
    });
    expect($result)->toBe('render-finished')->and(TenantContext::currentId())->toBeNull();
    expect(fn () => $middleware->handle($job, function () {
        expect(TenantContext::currentId())->toBe(41);
        throw new RuntimeException('Render interrupted');
    }))->toThrow(RuntimeException::class, 'Render interrupted');
    expect(app(TenantState::class)->teamId)->toBeNull();
    $middleware->handle(new stdClass(), function () {
        expect(TenantContext::currentId())->toBeNull();
    });
});

it('clears request tenant and permission state before a reused worker serves a guest', function () {
    Filament::setTenant(null);
    $user = User::factory()->create();
    $team = Team::factory()->create(['user_id' => $user->id]);
    $user->forceFill(['current_team_id' => $team->id])->save();
    $this->actingAs($user, 'sanctum');
    $request = Request::create('/api/music/projects');
    $context = new SetTenantContext();
    $permissions = new SetPermissionsTeamContext();
    $response = $context->handle($request, fn () => $permissions->handle($request, function () use ($team) {
        expect(TenantContext::currentId())->toBe($team->id)->and(getPermissionsTeamId())->toBe($team->id);

        return response('ok');
    }));
    $permissions->terminate($request, $response);
    $context->terminate($request, $response);
    expect(TenantContext::currentId())->toBeNull()->and(getPermissionsTeamId())->toBeNull();
    auth('sanctum')->forgetUser();
    $context->handle($request, function () {
        expect(TenantContext::currentId())->toBeNull();

        return response('guest');
    });
    expect(TenantContext::forUser(null))->toBeNull()
        ->and(TenantContext::forUser(new GenericUser(['id' => 1])))->toBeNull()
        ->and(TenantContext::forUser(User::factory()->make()))->toBeNull();
});

it('uses the panel tenant as a fallback and switches permission scope only for tenant events', function () {
    TenantContext::clear();
    $user = User::factory()->create();
    $team = Team::factory()->create(['user_id' => $user->id]);
    Filament::setTenant($team, isQuiet: true);
    try {
        expect(TenantContext::currentId())->toBe($team->id);
        $listener = new SwitchTeam();
        setPermissionsTeamId(null);
        $listener->handle(new stdClass());
        expect(getPermissionsTeamId())->toBeNull();
        $listener->handle(new TenantSet($team, $user));
        expect(getPermissionsTeamId())->toBe($team->id);
        TenantContext::set($team->id + 1);
        expect(TenantContext::currentId())->toBe($team->id + 1);
    } finally {
        TenantContext::clear();
        Filament::setTenant(null);
        setPermissionsTeamId(null);
    }
});
