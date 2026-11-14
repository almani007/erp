<?php

declare(strict_types=1);

namespace Saas\Presentation\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Shared\Domain\Exception\InsufficientPermission;
use Symfony\Component\HttpFoundation\Response;

/** Platform operators only. The flag cannot be set through any API. */
final class PlatformAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! (bool) $request->user()?->getAttribute('is_platform_admin')) {
            throw InsufficientPermission::for('platform administration');
        }

        return $next($request);
    }
}
