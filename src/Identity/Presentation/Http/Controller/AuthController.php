<?php

declare(strict_types=1);

namespace Identity\Presentation\Http\Controller;

use Identity\Domain\Exception\AccountDisabled;
use Identity\Domain\Exception\AuthenticationFailed;
use Identity\Infrastructure\Persistence\Eloquent\User;
use Identity\Infrastructure\Provider\IdentityServiceProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Organization\Infrastructure\Persistence\Eloquent\Tenant;

final class AuthController
{
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::query()->where('email', mb_strtolower($credentials['email']))->first();

        // Same error for "no such user" and "wrong password".
        if ($user === null || ! Hash::check($credentials['password'], $user->password)) {
            throw AuthenticationFailed::invalidCredentials();
        }

        $tenantActive = Tenant::query()->whereKey($user->tenant_id)->where('status', 'active')->exists();

        if (! $user->isActive() || ! $tenantActive) {
            throw AccountDisabled::forUser($user->id);
        }

        $user->forceFill(['last_login_at' => now()])->save();

        $token = $user->createToken('spa', ['*'], now()->addHours(12));

        return new JsonResponse(['data' => [
            'token' => $token->plainTextToken,
            'expires_at' => $token->accessToken->expires_at?->toIso8601String(),
        ] + $this->profile($user)]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return new JsonResponse(['data' => ['logged_out' => true]]);
    }

    public function me(Request $request): JsonResponse
    {
        return new JsonResponse(['data' => $this->profile($request->user())]);
    }

    /** @return array<string, mixed> */
    private function profile(User $user): array
    {
        $isOwner = $user->hasRole(IdentityServiceProvider::OWNER_ROLE);

        return [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'tenant_id' => $user->tenant_id,
                'all_branches' => $user->all_branches,
            ],
            'roles' => $user->getRoleNames()->values(),
            // "*" tells the client every permission is held; the server still checks each request.
            'permissions' => $isOwner ? ['*'] : $user->getAllPermissions()->pluck('name')->values(),
        ];
    }
}
