<?php

namespace LaravelScale\LaravelScale;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

class InstallCommand extends Command
{
    protected $signature = 'scale:install
                            {--no-octane : Skip running octane:install (e.g. if already installed)}
                            {--no-dockerignore : Skip publishing .dockerignore}
                            {--no-gitignore : Skip updating .gitignore}
                            {--no-wayfinder : Skip Wayfinder vite/gitignore adjustments}
                            {--no-ziggy : Skip Ziggy vendor-path fix for Docker frontend builds}';

    protected $description = 'Publish Docker files and optionally install Octane (FrankenPHP) for production scaling';

    private const GITIGNORE_MARKER = '# Laravel Scale';

    public function handle(): int
    {
        $this->info('Publishing Docker files...');
        $this->call('vendor:publish', ['--tag' => 'laravel-scale', '--force' => true]);

        if (! $this->option('no-dockerignore')) {
            $this->info('Publishing .dockerignore...');
            $this->call('vendor:publish', ['--tag' => 'laravel-scale-ignore', '--force' => true]);
        }

        if (! $this->option('no-octane')) {
            $this->info('Installing Octane (FrankenPHP)...');
            $this->call('octane:install', ['--server' => 'frankenphp']);
            $this->ensureOctaneInRequire();
        }

        $usesWayfinder = ! $this->option('no-wayfinder') && $this->usesWayfinder();
        if ($usesWayfinder) {
            $this->info('Wayfinder detected: adjusting Vite config and .gitignore for Docker builds...');
            $this->applyWayfinderViteFix();
        }

        if (! $this->option('no-ziggy') && $this->usesZiggyVendorPath()) {
            $this->info('Ziggy (vendor path) detected: switching to ziggy-js so Docker frontend build can resolve it...');
            $this->applyZiggyFix();
        }

        if (! $this->option('no-gitignore')) {
            $this->info('Updating .gitignore...');
            $this->updateGitignore($usesWayfinder);
        }

        $this->info('Ensuring proxy and HTTPS handling in bootstrap/app.php...');
        $this->ensureTrustProxiesInBootstrap();

        $this->info('Registering ForceHttpsServiceProvider so production uses https:// without the package installed...');
        $this->ensureForceHttpsProviderRegistered();

        $this->info('Registering ForceHttpsMiddleware at start of web stack so asset URLs use https...');
        $this->ensureForceHttpsMiddlewareRegistered();

        $this->newLine();
        $this->info('Done. Commit docker/, .dockerignore, app/Providers/ForceHttpsServiceProvider.php, app/Http/Middleware/ForceHttpsMiddleware.php, bootstrap/providers.php, bootstrap/app.php, and config/octane.php to your repo. Ensure your app is stateless: session and cache in DB (or Redis), files on S3/external. See README in docker/.');

        return self::SUCCESS;
    }

    private function ensureTrustProxiesInBootstrap(): void
    {
        $path = base_path('bootstrap/app.php');
        if (! file_exists($path)) {
            return;
        }

        $contents = file_get_contents($path);
        if (str_contains($contents, 'trustProxies')) {
            return;
        }

        $insert = "        \$middleware->trustProxies(at: '*');\n        \$middleware->statefulApi();\n\n";
        $pattern = '/(->withMiddleware\s*\(function\s*\([^)]+\)[^{]*\{\s*\n)/';
        $contents = preg_replace($pattern, '$1'.$insert, $contents, 1);

        if ($contents !== null) {
            file_put_contents($path, $contents);
        }
    }

    private function ensureForceHttpsProviderRegistered(): void
    {
        $providerClass = 'App\\Providers\\ForceHttpsServiceProvider::class';
        $providerPath = base_path('app/Providers/ForceHttpsServiceProvider.php');

        if (! file_exists($providerPath)) {
            return;
        }

        $providersPath = base_path('bootstrap/providers.php');
        if (! file_exists($providersPath)) {
            return;
        }

        $contents = file_get_contents($providersPath);
        if (str_contains($contents, 'ForceHttpsServiceProvider')) {
            return;
        }

        // Append to the providers array (before the closing ];)
        $contents = preg_replace(
            '/(\n)(\s*)\];\s*$/',
            '$1$2    '.$providerClass.",\n$2];",
            $contents,
            1
        );

        if ($contents !== null) {
            file_put_contents($providersPath, $contents);
        }
    }

    private function ensureForceHttpsMiddlewareRegistered(): void
    {
        $middlewarePath = base_path('app/Http/Middleware/ForceHttpsMiddleware.php');
        if (! file_exists($middlewarePath)) {
            return;
        }

        $path = base_path('bootstrap/app.php');
        if (! file_exists($path)) {
            return;
        }

        $contents = file_get_contents($path);
        if (str_contains($contents, 'ForceHttpsMiddleware')) {
            return;
        }

        $insert = "\n        \$middleware->web(prepend: [\\App\\Http\\Middleware\\ForceHttpsMiddleware::class]);";
        $replaced = preg_replace(
            '/(\$middleware->statefulApi\(\);\s*\n)/',
            '$1'.$insert."\n",
            $contents,
            1
        );
        if ($replaced === $contents) {
            $replaced = preg_replace(
                '/(\$middleware->trustProxies\([^)]*\);\s*\n)/',
                '$1'.$insert."\n",
                $contents,
                1
            );
        }
        if ($replaced !== null && $replaced !== $contents) {
            file_put_contents($path, $replaced);
        }
    }

    private function ensureOctaneInRequire(): void
    {
        $path = base_path('composer.json');
        if (! file_exists($path)) {
            return;
        }

        $json = json_decode(file_get_contents($path), true);
        if (! is_array($json)) {
            return;
        }

        $require = $json['require'] ?? [];
        $requireDev = $json['require-dev'] ?? [];
        $version = $require['laravel/octane'] ?? $requireDev['laravel/octane'] ?? '^2.13';

        if (isset($require['laravel/octane'])) {
            return;
        }

        if (isset($requireDev['laravel/octane'])) {
            unset($requireDev['laravel/octane']);
            $json['require-dev'] = $requireDev;
        }

        $require['laravel/octane'] = $version;
        $json['require'] = $require;
        ksort($json['require']);

        file_put_contents($path, json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

        $this->info('Ensuring laravel/octane is in require (needed for production Docker image)...');
        Process::path(base_path())->run('composer update laravel/octane --no-interaction');
    }

    private function usesWayfinder(): bool
    {
        $composerPath = base_path('composer.json');
        if (file_exists($composerPath)) {
            $json = json_decode(file_get_contents($composerPath), true);
            if (isset($json['require']['laravel/wayfinder']) || isset($json['require-dev']['laravel/wayfinder'])) {
                return true;
            }
        }

        $packagePath = base_path('package.json');
        if (file_exists($packagePath)) {
            $json = json_decode(file_get_contents($packagePath), true);
            $deps = array_merge($json['dependencies'] ?? [], $json['devDependencies'] ?? []);
            if (isset($deps['@laravel/vite-plugin-wayfinder'])) {
                return true;
            }
        }

        return false;
    }

    private function getViteConfigPath(): ?string
    {
        foreach (['vite.config.ts', 'vite.config.js', 'vite.config.mjs'] as $name) {
            $path = base_path($name);
            if (file_exists($path)) {
                return $path;
            }
        }

        return null;
    }

    private function applyWayfinderViteFix(): void
    {
        $path = $this->getViteConfigPath();
        if (! $path) {
            return;
        }

        $contents = file_get_contents($path);

        // Remove wayfinder import (single line)
        $contents = preg_replace(
            "/^import\s+(?:\{\s*wayfinder\s*\}|wayfinder)\s+from\s+['\"]@laravel\/vite-plugin-wayfinder['\"];?\s*?\n/m",
            '',
            $contents
        );

        // Remove wayfinder() from plugins array (may be multiline)
        $contents = preg_replace(
            '/,\s*wayfinder\s*\(\s*[\s\S]*?\\)\s*/',
            ', ',
            $contents
        );
        $contents = preg_replace(
            '/wayfinder\s*\(\s*[\s\S]*?\\)\s*,\s*/',
            '',
            $contents
        );

        file_put_contents($path, $contents);
    }

    /** @return array<string> */
    private function getJsEntryPaths(): array
    {
        $dir = base_path('resources/js');
        if (! is_dir($dir)) {
            return [];
        }

        $paths = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if ($file->isFile() && preg_match('/\.(js|jsx|ts|tsx|mjs|cjs)$/', $file->getFilename())) {
                $paths[] = $file->getPathname();
            }
        }

        return $paths;
    }

    private function usesZiggyVendorPath(): bool
    {
        $needle = 'vendor/tightenco/ziggy';
        foreach ($this->getJsEntryPaths() as $path) {
            if (str_contains(file_get_contents($path), $needle)) {
                return true;
            }
        }

        return false;
    }

    private function applyZiggyFix(): void
    {
        $rewritten = 0;
        $replacement = "from 'ziggy-js'";
        foreach ($this->getJsEntryPaths() as $path) {
            $contents = file_get_contents($path);
            $newContents = preg_replace(
                "/from\s+['\"][^'\"]*vendor\/tightenco\/ziggy['\"]/",
                $replacement,
                $contents,
                -1,
                $count
            );
            if ($count > 0) {
                file_put_contents($path, $newContents);
                $rewritten += $count;
            }
        }

        $packagePath = base_path('package.json');
        if ($rewritten > 0 && file_exists($packagePath)) {
            $json = json_decode(file_get_contents($packagePath), true);
            if (is_array($json)) {
                $json['dependencies'] = $json['dependencies'] ?? [];
                if (empty($json['dependencies']['ziggy-js'])) {
                    $json['dependencies']['ziggy-js'] = '^2.6.0';
                    ksort($json['dependencies']);
                    file_put_contents(
                        $packagePath,
                        json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n"
                    );
                    $this->info('Added ziggy-js to package.json dependencies. Run npm install.');
                }
            }
        }
    }

    private const GITIGNORE_WAYFINDER_MARKER = '# Laravel Scale - Wayfinder';

    private function updateGitignore(bool $usesWayfinder = false): void
    {
        $path = base_path('.gitignore');

        if (! file_exists($path)) {
            return;
        }

        $contents = file_get_contents($path);

        if (str_contains($contents, self::GITIGNORE_MARKER)) {
            if ($usesWayfinder && ! str_contains($contents, self::GITIGNORE_WAYFINDER_MARKER)) {
                $contents .= "\n".self::GITIGNORE_WAYFINDER_MARKER." - commit generated routes/actions/wayfinder\n"
                    ."!resources/js/routes/\n"
                    ."!resources/js/actions/\n"
                    ."!resources/js/wayfinder/\n";
                file_put_contents($path, $contents);
            }

            return;
        }

        $octaneLine = $this->option('no-octane') ? '' : "!config/octane.php\n";
        $wayfinderLines = $usesWayfinder
            ? "!resources/js/routes/\n!resources/js/actions/\n!resources/js/wayfinder/\n"
            : '';

        $block = "\n".self::GITIGNORE_MARKER." - ensure these are committed (un-ignore if needed)\n"
            ."!docker/\n"
            .$octaneLine
            .$wayfinderLines
            ."\n"
            .self::GITIGNORE_MARKER." - runtime-generated (do not commit)\n"
            ."frankenphp\n"
            ."frankenphp-worker.php\n"
            ."**/caddy\n";

        file_put_contents($path, $contents.$block);
    }
}
