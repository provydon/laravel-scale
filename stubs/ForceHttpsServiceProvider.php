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
        if (! $this->app->runningInConsole() && ! $this->app->environment('local')) {
            URL::forceScheme('https');
        }
    }
}
