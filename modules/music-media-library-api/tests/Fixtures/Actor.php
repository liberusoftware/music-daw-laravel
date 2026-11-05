<?php

namespace Liberu\Music\MediaLibraryApi\Tests\Fixtures;

use Illuminate\Foundation\Auth\User;
use Laravel\Sanctum\HasApiTokens;

class Actor extends User
{
    use HasApiTokens;
}
