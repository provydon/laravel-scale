<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

class ForceHttpsMiddleware
{
    /**
     * Force https:// for asset and link URLs when behind a reverse proxy (avoids Mixed Content).
     * Runs at the start of the web stack so Vite/asset URLs use https.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $appUrl = config('app.url', '');
        $host = $request->getHost();

        $productionDomains = ['onrender.com', 'fly.dev', 'railway.app', 'up.railway.app'];
        $isProductionDomain = $host && array_filter($productionDomains, fn ($d) => str_ends_with($host, $d)) !== [];
        $shouldForce = $isProductionDomain
            || ! app()->environment('local')
            || str_starts_with($appUrl, 'https://');

        if ($shouldForce) {
            URL::forceScheme('https');
        }

        return $next($request);
    }
}
