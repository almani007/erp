<?php

declare(strict_types=1);

use Identity\Presentation\Http\Middleware\RequiresPermission;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Integration\Presentation\Http\Middleware\Idempotent;
use Shared\Infrastructure\Http\DomainExceptionRenderer;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
        apiPrefix: 'api/v1',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // The SPA authenticates with Sanctum bearer tokens, so the API stays
        // stateless: no session cookies and therefore no CSRF surface.
        $middleware->alias([
            'permission' => RequiresPermission::class,
            'idempotent' => Idempotent::class,
            'module' => \Saas\Presentation\Http\Middleware\EnsureModuleEnabled::class,
            'platform' => \Saas\Presentation\Http\Middleware\PlatformAdmin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            static fn (Request $request): bool => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(
            static fn (\DomainException $e, Request $request) => (new DomainExceptionRenderer)->render($request, $e),
        );
    })
    ->create();
