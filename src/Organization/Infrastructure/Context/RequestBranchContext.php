<?php

declare(strict_types=1);

namespace Organization\Infrastructure\Context;

use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Http\Request;
use Organization\Domain\Contract\BranchContext;
use Organization\Domain\Exception\BranchAccessDenied;
use Organization\Infrastructure\Persistence\Eloquent\Branch;

/**
 * The client names the branch it is working in via the X-Branch-ID header;
 * the server decides whether that is allowed. The branch must belong to the
 * user's tenant (Branch is tenant-scoped) and to the user's branch grants.
 */
final class RequestBranchContext implements BranchContext
{
    private bool $resolved = false;

    private ?string $activeBranchId = null;

    public function __construct(
        private readonly Request $request,
        private readonly AuthFactory $auth,
    ) {}

    public function activeBranchId(): ?string
    {
        if ($this->resolved) {
            return $this->activeBranchId;
        }

        $requested = $this->request->header('X-Branch-ID');

        if (is_string($requested) && $requested !== '') {
            if (! $this->canAccess($requested)) {
                throw BranchAccessDenied::forBranch($requested);
            }

            $this->activeBranchId = $requested;
        }

        $this->resolved = true;

        return $this->activeBranchId;
    }

    public function requireActiveBranchId(): string
    {
        return $this->activeBranchId() ?? throw BranchAccessDenied::noneSelected();
    }

    public function canAccess(string $branchId): bool
    {
        if (! \Symfony\Component\Uid\Uuid::isValid($branchId)) {
            return false;
        }

        $allowed = $this->allowedBranchIds();

        if ($allowed !== null) {
            return in_array($branchId, $allowed, true);
        }

        return Branch::query()->whereKey($branchId)->exists();
    }

    public function allowedBranchIds(): ?array
    {
        $user = $this->auth->guard('sanctum')->user();

        if ($user === null) {
            return [];
        }

        if ((bool) $user->getAttribute('all_branches')) {
            return null;
        }

        return array_values($user->branches()->pluck('branches.id')->all());
    }
}
