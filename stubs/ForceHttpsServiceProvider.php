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
        try {
            $host = $this->app->make('request')->getHost();
        } catch (\Throwable) {
            $host = '';
        }
        // Force HTTPS for known production domains (Render, Fly, Railway) even if APP_ENV/APP_URL are wrong
        $productionDomains = ['onrender.com', 'fly.dev', 'railway.app', 'up.railway.app'];
        $isProductionDomain = $host && array_filter($productionDomains, fn ($d) => str_ends_with($host, $d)) !== [];
        $shouldForce = $isProductionDomain
            || ! $this->app->environment('local')
            || str_starts_with($appUrl, 'https://');
        if ($shouldForce) {
            URL::forceScheme('https');
        }
    }
}
