<?php

declare(strict_types=1);

namespace Saas\Presentation\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Saas\Application\Entitlements;
use Symfony\Component\HttpFoundation\Response;

/**
 * `->middleware('module:purchasing')` — rejects the request when the
 * tenant is suspended or its plan does not include the module. Apply after
 * `auth:sanctum`.
 */
final class EnsureModuleEnabled
{
    public function __construct(private readonly Entitlements $entitlements) {}

    public function handle(Request $request, Closure $next, string $module): Response
    {
        if (! $this->entitlements->tenantActive()) {
            return new JsonResponse(['error' => ['code' => 'TENANT_SUSPENDED', 'message' => 'This account is suspended. Contact your provider.']], 403);
        }

        if (! $this->entitlements->moduleEnabled($module)) {
            return new JsonResponse(['error' => ['code' => 'MODULE_NOT_ENABLED', 'message' => "The {$module} module is not part of your plan."]], 403);
        }

        return $next($request);
    }
}
