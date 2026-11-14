<?php

declare(strict_types=1);

namespace Organization\Domain\Contract;

interface BranchContext
{
    /**
     * Branch the current request operates in (X-Branch-ID header), or null
     * when the caller did not choose one.
     *
     * @throws \Organization\Domain\Exception\BranchAccessDenied when the user may not use that branch
     */
    public function activeBranchId(): ?string;

    /** @throws \Organization\Domain\Exception\BranchAccessDenied */
    public function requireActiveBranchId(): string;

    /** Whether the current user may operate in the given branch. */
    public function canAccess(string $branchId): bool;

    /**
     * Branch ids the current user is limited to, or null when unrestricted.
     *
     * @return list<string>|null
     */
    public function allowedBranchIds(): ?array;
}
