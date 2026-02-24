<?php

namespace App\Providers;

use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class ForceHttpsServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     * Forces https:// in non-local so asset and link URLs work behind reverse proxies (avoids Mixed Content).
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            return;
        }
        $appUrl = config('app.url', '');
        // Force HTTPS in production, or whenever APP_URL is https (e.g. prod with APP_ENV=local set by mistake)
        if ($this->app->environment('local') && ! str_starts_with($appUrl, 'https://')) {
            return;
        }
        URL::forceScheme('https');
    }
}
