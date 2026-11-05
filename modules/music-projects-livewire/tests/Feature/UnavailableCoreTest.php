<?php

use Illuminate\Auth\GenericUser;

it('requires authentication before disclosing the disabled core dependency', function () {
    $this->getJson('/music/workspaces/studio/sessions')->assertUnauthorized();
    $this->actingAs(new GenericUser(['id' => 1]));
    $this->getJson('/music/workspaces/studio/sessions')->assertServiceUnavailable();
});
