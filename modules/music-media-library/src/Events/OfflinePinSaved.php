<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibrary\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Liberu\Music\MediaLibrary\Data\OfflinePin;

final readonly class OfflinePinSaved implements ShouldDispatchAfterCommit
{
    public function __construct(public OfflinePin $pin, public string $actorId) {}
}
