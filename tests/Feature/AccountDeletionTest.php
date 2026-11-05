<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Jetstream\Contracts\DeletesTeams;
use Liberu\Foundation\JetstreamBridge\Actions\Jetstream\DeleteUser;
use Liberu\Foundation\Organizations\Models\Team;

it('deletes account credentials and owned teams while preserving another owners workspace', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $owned = Team::factory()->create(['user_id' => $user->id]);
    $shared = Team::factory()->create(['user_id' => $other->id]);
    $shared->users()->attach($user, ['role' => 'editor']);
    $account = $user->connectedAccounts()->create(['provider' => 'github', 'provider_id' => 'deletion-test', 'token' => 'test-provider-token']);
    $token = $user->createToken('desktop');
    $otherToken = $other->createToken('desktop');
    $deleter = Mockery::mock(DeletesTeams::class);
    $deleter->shouldReceive('delete')->once()->with(Mockery::on(fn ($team) => $team->is($owned)))
        ->andReturnUsing(fn ($team) => $team->purge());

    (new DeleteUser($deleter))->delete($user);

    expect(User::find($user->id))->toBeNull()->and($owned->fresh())->toBeNull()
        ->and($shared->fresh())->not->toBeNull()->and($other->fresh())->not->toBeNull()
        ->and(DB::table('team_user')->where('user_id', $user->id)->exists())->toBeFalse()
        ->and($account->fresh())->toBeNull()->and($token->accessToken->fresh())->toBeNull()
        ->and($otherToken->accessToken->fresh())->not->toBeNull();
});

it('rolls back membership and team cleanup if the team deletion dependency fails', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $owned = Team::factory()->create(['user_id' => $user->id]);
    $shared = Team::factory()->create(['user_id' => $other->id]);
    $shared->users()->attach($user, ['role' => 'editor']);
    $token = $user->createToken('desktop');
    $deleter = Mockery::mock(DeletesTeams::class);
    $deleter->shouldReceive('delete')->once()->andReturnUsing(function ($team) {
        $team->purge();
        throw new RuntimeException('Workspace cleanup interrupted');
    });
    expect(fn () => (new DeleteUser($deleter))->delete($user))->toThrow(RuntimeException::class, 'Workspace cleanup interrupted');
    expect($user->fresh())->not->toBeNull()->and($owned->fresh())->not->toBeNull()
        ->and($shared->users()->whereKey($user->id)->exists())->toBeTrue()
        ->and($token->accessToken->fresh())->not->toBeNull();
});

it('rejects an invalid account before invoking destructive dependencies', function () {
    $deleter = Mockery::mock(DeletesTeams::class);
    $deleter->shouldNotReceive('delete');
    expect(fn () => (new DeleteUser($deleter))->delete(new stdClass()))->toThrow(InvalidArgumentException::class);
});
