<?php

declare(strict_types=1);

namespace Shared\Domain\Contract;

interface AuditTrail
{
    /**
     * Append an immutable audit record for a material business action.
     *
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function record(
        string $action,
        string $subjectType,
        ?string $subjectId,
        ?array $before = null,
        ?array $after = null,
        ?string $reason = null,
    ): void;
}
