<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        // Generate a per-request CSP nonce and share with views
        $nonce = Str::random(32);
        app()->instance('csp-nonce', $nonce);
        view()->share('cspNonce', $nonce);

        $response = $next($request);

        // Files served by SecureFileController (e.g. a PDF receipt) may be embedded INLINE in our
        // own pages (the claim report). Allow SAME-ORIGIN framing for that route only — every
        // other response stays DENY / frame-ancestors 'none'. External framing is still blocked,
        // so this doesn't open a clickjacking vector.
        $allowSameOriginFrame = $request->routeIs('secure.file');

        // Prevent clickjacking
        $response->headers->set('X-Frame-Options', $allowSameOriginFrame ? 'SAMEORIGIN' : 'DENY');

        // Prevent MIME-type sniffing
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        // Referrer policy — send origin only on cross-origin requests
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // Restrict browser features
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');

        // Content-Security-Policy — nonce-based for scripts, unsafe-inline kept for styles
        // (Bootstrap/Tailwind inline styles are too pervasive for nonce-based enforcement)
        $response->headers->set('Content-Security-Policy', implode('; ', [
            "default-src 'self'",
            // Nonce covers <script> blocks; 'unsafe-hashes' permits existing onclick= handlers
            // during incremental migration to addEventListener. Remove 'unsafe-hashes' once
            // all inline event handlers are converted.
            "script-src 'self' 'nonce-{$nonce}' 'unsafe-hashes' https://cdn.jsdelivr.net",
            "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://fonts.googleapis.com",
            "font-src 'self' https://cdn.jsdelivr.net https://fonts.gstatic.com",
            "img-src 'self' data: blob: https://api.qrserver.com",
            "connect-src 'self'",
            'frame-ancestors '.($allowSameOriginFrame ? "'self'" : "'none'"),
            "base-uri 'self'",
            "form-action 'self'",
        ]));

        // HSTS — enforce HTTPS for 1 year (only when serving over HTTPS)
        if ($request->secure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        // Remove server identification headers
        $response->headers->remove('X-Powered-By');
        $response->headers->remove('Server');

        // Prevent caching of pages containing sensitive data (authenticated routes)
        if ($request->user()) {
            $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, private');
            $response->headers->set('Pragma', 'no-cache');
        }

        return $response;
    }
}
