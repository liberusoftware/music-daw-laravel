<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibrary\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Liberu\Music\MediaLibrary\Data\QuarantineCollection;

final readonly class QuarantineCollected implements ShouldDispatchAfterCommit
{
    public function __construct(public QuarantineCollection $collection, public string $actorId) {}
}
