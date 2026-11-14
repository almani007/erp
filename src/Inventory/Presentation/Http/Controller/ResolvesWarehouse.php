<?php

declare(strict_types=1);

namespace Inventory\Presentation\Http\Controller;

use Organization\Domain\Contract\BranchContext;
use Organization\Domain\Exception\BranchAccessDenied;
use Organization\Infrastructure\Persistence\Eloquent\Warehouse;

trait ResolvesWarehouse
{
    /** A warehouse of the caller's tenant, in a branch the caller may use. */
    private function warehouse(string $warehouseId, BranchContext $branches): Warehouse
    {
        $warehouse = Warehouse::query()->where('status', 'active')->findOrFail($warehouseId);

        if (! $branches->canAccess($warehouse->branch_id)) {
            throw BranchAccessDenied::forBranch($warehouse->branch_id);
        }

        return $warehouse;
    }
}
