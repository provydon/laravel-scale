<?php

namespace LaravelScale\LaravelScale\Tests\Feature;

use LaravelScale\LaravelScale\Tests\TestCase;

class InstallCommandTest extends TestCase
{
    public function test_scale_install_publishes_force_https_provider_and_registers_it(): void
    {
        $this->artisan('scale:install', [
            '--no-octane' => true,
            '--no-dockerignore' => true,
            '--no-gitignore' => true,
            '--no-wayfinder' => true,
            '--no-ziggy' => true,
        ])
            ->expectsQuestion('Is App Monolithic: Does this app have a frontend in same repo as backend (React, Vue, Svelte, etc.)?', true)
            ->assertSuccessful();

        $providerPath = base_path('app/Providers/ForceHttpsServiceProvider.php');
        $this->assertFileExists($providerPath);
        $this->assertStringContainsString('URL::forceScheme', file_get_contents($providerPath));

        $providersPath = base_path('bootstrap/providers.php');
        $this->assertFileExists($providersPath);
        $this->assertStringContainsString('ForceHttpsServiceProvider', file_get_contents($providersPath));

        $middlewarePath = base_path('app/Http/Middleware/ForceHttpsMiddleware.php');
        $this->assertFileExists($middlewarePath);
        $this->assertStringContainsString('URL::forceScheme', file_get_contents($middlewarePath));
        // Middleware is registered in bootstrap/app.php when it contains statefulApi()/trustProxies (see ensureForceHttpsMiddlewareRegistered)
    }

    public function test_scale_install_publishes_docker_directory(): void
    {
        $this->artisan('scale:install', [
            '--no-octane' => true,
            '--no-dockerignore' => true,
            '--no-gitignore' => true,
            '--no-wayfinder' => true,
            '--no-ziggy' => true,
        ])
            ->expectsQuestion('Is App Monolithic: Does this app have a frontend in same repo as backend (React, Vue, Svelte, etc.)?', true)
            ->assertSuccessful();

        $this->assertFileExists(base_path('docker/Dockerfile'));
        $this->assertFileExists(base_path('docker/docker-entrypoint.sh'));
    }
}
