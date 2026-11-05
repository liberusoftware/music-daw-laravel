<?php

use Laravel\Sanctum\Sanctum;
use Liberu\Music\ProjectsApi\Tests\Fixtures\Actor;

it('requires authentication before disclosing core module availability', function () {
    $this->getJson('/api/v1/music-projects/workspaces/studio/capabilities')->assertUnauthorized()->assertJsonPath('status', 401);
    Sanctum::actingAs((new Actor())->forceFill(['id' => 1]), ['music.projects.read']);
    $this->getJson('/api/v1/music-projects/workspaces/studio/capabilities')->assertServiceUnavailable()->assertJsonPath('status', 503);
});
