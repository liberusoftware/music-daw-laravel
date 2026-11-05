<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibrary\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

final readonly class UploadBudgetChanged implements ShouldDispatchAfterCommit
{
    public function __construct(public string $workspaceId, public int $reservedBytes, public int $deltaBytes, public array $uploadIds, public string $actorId) {}
}
