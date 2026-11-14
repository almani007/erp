<?php

declare(strict_types=1);

namespace Organization\Domain\Exception;

final class BranchAccessDenied extends \DomainException
{
    public static function forBranch(string $branchId): self
    {
        return new self('You do not have access to the selected branch.');
    }

    public static function noneSelected(): self
    {
        return new self('This action needs an active branch. Select a branch and try again.');
    }
}
