<?php

declare(strict_types=1);

namespace Identity\Presentation\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Shared\Domain\Exception\InsufficientPermission;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route gate: `->middleware('permission:sales.sale.create')`.
 * Several permissions separated by `|` mean "any of".
 */
final class RequiresPermission
{
    public function handle(Request $request, Closure $next, string $permissions): Response
    {
        $user = $request->user();

        if ($user === null) {
            throw new AuthenticationException;
        }

        foreach (explode('|', $permissions) as $permission) {
            if ($user->can($permission)) {
                return $next($request);
            }
        }

        throw InsufficientPermission::for($permissions);
    }
}
