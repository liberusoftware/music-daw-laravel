<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibrary\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Liberu\Music\MediaLibrary\Data\MediaPack;

final readonly class MediaPackSaved implements ShouldDispatchAfterCommit
{
    public function __construct(public MediaPack $pack, public string $actorId) {}
}
