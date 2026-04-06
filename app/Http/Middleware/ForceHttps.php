<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Force HTTPS in production environments.
 *
 * When FORCE_HTTPS=true, all HTTP requests are 301-redirected to HTTPS.
 * Also sets URL generator to always produce HTTPS URLs.
 */
class ForceHttps
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->shouldForce() && !$request->secure()) {
            return redirect()->secure($request->getRequestUri(), 301);
        }

        // Always generate HTTPS URLs when forced
        if ($this->shouldForce()) {
            \Illuminate\Support\Facades\URL::forceScheme('https');
        }

        return $next($request);
    }

    private function shouldForce(): bool
    {
        // Allow explicit opt-in via env var. Default to true in production.
        if (env('FORCE_HTTPS') !== null) {
            return filter_var(env('FORCE_HTTPS'), FILTER_VALIDATE_BOOLEAN);
        }
        return app()->environment('production');
    }
}
