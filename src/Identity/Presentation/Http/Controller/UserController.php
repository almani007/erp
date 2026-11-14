<?php

declare(strict_types=1);

namespace Identity\Presentation\Http\Controller;

use Identity\Infrastructure\Persistence\Eloquent\User;
use Identity\Infrastructure\Provider\IdentityServiceProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Shared\Domain\Contract\AuditTrail;
use Shared\Domain\Contract\TenantContext;
use Shared\Domain\Exception\InsufficientPermission;
use Shared\Infrastructure\Validation\TenantRule;

final class UserController
{
    public function index(): JsonResponse
    {
        $users = User::query()->inCurrentTenant()->with(['roles:id,name', 'branches:id,name'])->orderBy('name')->get();

        return new JsonResponse(['data' => $users->map($this->present(...))]);
    }

    public function store(Request $request, TenantContext $tenant, AuditTrail $audit): JsonResponse
    {
        $request->merge(['email' => mb_strtolower((string) $request->input('email'))]);

        $v = $request->validate($this->rules() + [
            'email' => ['required', 'email', 'max:150', Rule::unique('users', 'email')],
            'password' => ['required', 'string', Password::min(10)],
            'name' => ['required', 'string', 'max:150'],
        ]);
        $this->guardOwnerRole($request, $v['roles'] ?? []);
        app(\Saas\Application\Entitlements::class)->assertWithinLimit('max_users', User::query()->inCurrentTenant()->count(), 'users');

        $user = DB::transaction(function () use ($v, $tenant, $audit): User {
            $user = new User([
                'name' => $v['name'],
                'email' => $v['email'],
                'password' => $v['password'],
                'status' => 'active',
                'all_branches' => $v['all_branches'] ?? false,
            ]);
            $user->tenant_id = $tenant->requireId();
            $user->save();

            $user->syncRoles($v['roles'] ?? []);
            $user->branches()->sync($v['branch_ids'] ?? []);

            $audit->record('identity.user.created', 'user', $user->id, null, [
                'email' => $user->email, 'roles' => $v['roles'] ?? [],
            ]);

            return $user;
        });

        return new JsonResponse(['data' => $this->present($user->load(['roles:id,name', 'branches:id,name']))], 201);
    }

    public function update(Request $request, string $userId, AuditTrail $audit): JsonResponse
    {
        $user = User::query()->inCurrentTenant()->findOrFail($userId);

        $v = $request->validate($this->rules() + [
            'name' => ['sometimes', 'string', 'max:150'],
            'password' => ['sometimes', 'string', Password::min(10)],
            'status' => ['sometimes', 'in:active,disabled'],
        ]);
        $this->guardOwnerRole($request, $v['roles'] ?? []);

        if (($v['status'] ?? null) === 'disabled' && $user->is($request->user())) {
            throw new \DomainException('You cannot disable your own account.');
        }

        DB::transaction(function () use ($user, $v, $audit): void {
            $before = ['status' => $user->status, 'roles' => $user->getRoleNames()->all()];

            $user->fill(array_intersect_key($v, array_flip(['name', 'password', 'status', 'all_branches'])))->save();

            if (array_key_exists('roles', $v)) {
                $user->syncRoles($v['roles']);
            }
            if (array_key_exists('branch_ids', $v)) {
                $user->branches()->sync($v['branch_ids']);
            }
            if (($v['status'] ?? null) === 'disabled' || isset($v['password'])) {
                $user->tokens()->delete(); // revoke every session
            }

            $audit->record('identity.user.updated', 'user', $user->id, $before, [
                'status' => $user->status, 'roles' => $user->getRoleNames()->all(),
            ]);
        });

        return new JsonResponse(['data' => $this->present($user->fresh(['roles:id,name', 'branches:id,name']))]);
    }

    /** @return array<string, mixed> */
    private function rules(): array
    {
        return [
            'all_branches' => ['sometimes', 'boolean'],
            'roles' => ['sometimes', 'array'],
            'roles.*' => ['string', Rule::exists('roles', 'name')],
            'branch_ids' => ['sometimes', 'array'],
            'branch_ids.*' => ['uuid', TenantRule::exists('branches')],
        ];
    }

    /**
     * Only an owner may hand out the owner role.
     *
     * @param  list<string>  $roles
     */
    private function guardOwnerRole(Request $request, array $roles): void
    {
        $owner = IdentityServiceProvider::OWNER_ROLE;

        if (in_array($owner, $roles, true) && ! $request->user()->hasRole($owner)) {
            throw InsufficientPermission::for('assign '.$owner);
        }
    }

    /** @return array<string, mixed> */
    private function present(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'status' => $user->status,
            'all_branches' => $user->all_branches,
            'roles' => $user->roles->pluck('name')->values(),
            'branch_ids' => $user->branches->pluck('id')->values(),
            'last_login_at' => $user->last_login_at?->toIso8601String(),
        ];
    }
}
