<?php

namespace LaravelScale\LaravelScale;

use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class LaravelScaleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Force HTTPS in non-local environments so asset and link URLs use https:// (avoids Mixed Content when behind a reverse proxy)
        if (! $this->app->runningInConsole() && ! $this->app->environment('local')) {
            URL::forceScheme('https');
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../docker' => base_path('docker'),
            ], 'laravel-scale');

            $this->publishes([
                __DIR__ . '/../stubs/.dockerignore' => base_path('.dockerignore'),
            ], 'laravel-scale-ignore');

            $this->commands([InstallCommand::class]);
        }
    }
}
