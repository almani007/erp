<?php

declare(strict_types=1);

namespace Shared\Domain\Contract;

interface DocumentNumbers
{
    /**
     * Next sequential number for a document type within a scope (a company
     * or branch id), e.g. next($tenant, 'sale', $branchId, 'INV') → INV-000042.
     *
     * Implementations must be safe under concurrent callers.
     */
    public function next(string $tenantId, string $type, string $scopeId, string $prefix): string;
}
