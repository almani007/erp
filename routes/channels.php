<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('tenant.{tenantId}', static function ($user, string $tenantId): bool {
    return $user->tenant_id === $tenantId;
});

Broadcast::channel('branch.{branchId}', static function ($user, string $branchId): bool {
    return $user->hasAccessToBranch($branchId);
});
