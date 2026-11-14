<?php

declare(strict_types=1);

namespace Identity\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Spatie\Permission\Models\Role;

/**
 * Roles are platform-defined in this release (seeded), so tenants can
 * assign them but not edit them. Per-tenant custom roles are a later item.
 */
final class RoleController
{
    public function index(): JsonResponse
    {
        $roles = Role::query()->with('permissions:id,name')->orderBy('name')->get()
            ->map(static fn (Role $role): array => [
                'name' => $role->name,
                'permissions' => $role->permissions->pluck('name')->values(),
            ]);

        return new JsonResponse(['data' => $roles]);
    }
}
