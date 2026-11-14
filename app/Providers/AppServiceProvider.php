<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Force HTTPS scheme in production or when behind reverse proxy
        if (
            $this->app->environment('production')
            || str_starts_with((string) config('app.url'), 'https://')
            || request()->header('x-forwarded-proto') === 'https'
            || request()->isSecure()
        ) {
            URL::forceScheme('https');
        }
    }
}
