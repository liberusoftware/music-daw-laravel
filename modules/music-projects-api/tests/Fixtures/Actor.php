<?php

namespace Liberu\Music\ProjectsApi\Tests\Fixtures;

use Illuminate\Foundation\Auth\User;
use Laravel\Sanctum\HasApiTokens;

class Actor extends User
{
    use HasApiTokens;
}
