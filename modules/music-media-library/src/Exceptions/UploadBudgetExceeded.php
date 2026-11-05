<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibrary\Exceptions;

use Liberu\Music\MediaLibrary\Data\UploadBudget;
use RuntimeException;

final class UploadBudgetExceeded extends RuntimeException
{
    public function __construct(public readonly UploadBudget $budget, public readonly int $requestedBytes)
    {
        parent::__construct('This upload exceeds the workspace reserved media budget.');
    }
}
