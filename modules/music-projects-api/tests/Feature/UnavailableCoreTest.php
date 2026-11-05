<?php

use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Liberu\Music\ProjectsApi\MusicProjectsApiServiceProvider;
use Liberu\Music\ProjectsApi\Tests\Fixtures\Actor;

it('requires authentication before disclosing core module availability', function () {
    $this->getJson('/api/v1/music-projects/workspaces/studio/capabilities')->assertUnauthorized()->assertJsonPath('status', 401);
    Sanctum::actingAs((new Actor())->forceFill(['id' => 1]), ['music.projects.read']);
    $this->getJson('/api/v1/music-projects/workspaces/studio/capabilities')->assertServiceUnavailable()->assertJsonPath('status', 503);
});

it('supports a contract-only exception handler when consumers disable framework exception rendering in tests', function () {
    $this->withoutExceptionHandling();
    (new MusicProjectsApiServiceProvider(app()))->boot();
    expect(Route::has('music.projects.api.show'))->toBeTrue();
});
