<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibrary\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Liberu\Music\MediaLibrary\Data\MediaPack;

interface PackDistributionPolicy
{
    public function authorize(Authenticatable $actor, MediaPack $pack): void;
}
