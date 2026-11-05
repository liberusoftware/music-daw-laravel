<?php

declare(strict_types=1);

namespace Liberu\Music\MediaLibrary\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Validation\ValidationException;
use Liberu\Music\MediaLibrary\Contracts\UploadBudgetPolicy;

final readonly class ConfiguredUploadBudget implements UploadBudgetPolicy
{
    public function __construct(private Repository $config) {}

    public function maximumReservedBytes(Authenticatable $actor, string $workspaceId): ?int
    {
        $value = $this->config->get('music-media.max_reserved_bytes');
        if ($value === null) {
            return null;
        }
        $limit = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ((! is_string($value) && ! is_int($value)) || $limit === false) {
            throw ValidationException::withMessages(['budget' => 'The upload reservation limit must be a non-negative integer byte count or null.']);
        }

        return $limit;
    }
}
