<?php

namespace LaravelScale\LaravelScale;

use Illuminate\Support\ServiceProvider;

class LaravelScaleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
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
