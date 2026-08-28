<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withProviders([
        App\Providers\AuthServiceProvider::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        // ── Trusted proxies ────────────────────────────────────────────────────────
        // Production ingress is Cloudflare edge → cloudflared → nginx → PHP-FPM, so
        // every request arrives from a proxy. With nothing trusted (the previous state)
        // Laravel read the CONNECTION address instead of the forwarded one, which meant:
        //   • $request->ip() was the loopback address for everybody, so every
        //     SecurityAuditLog row recorded the same IP and ThreatDetector's IP-based
        //     signals could never fire;
        //   • every `throttle:*` limiter keyed on that one value, collapsing per-client
        //     buckets into a single global one;
        //   • $request->secure() was false, so SecurityHeaders never emitted HSTS even
        //     though the site is HTTPS-only at the edge.
        //
        // '*' is correct HERE and only because of that topology: nginx binds loopback
        // and cloudflared is the sole ingress, so no client can present its own
        // X-Forwarded-For. If the app is ever exposed directly, pin TRUSTED_PROXIES to
        // the real proxy addresses — trusting '*' on a directly reachable host lets a
        // client spoof its IP and forge exactly the audit trail described above.
        // Passed as a STRING on purpose: Laravel's TrustProxies treats the literal '*'
        // specially and splits a comma-separated list itself, so wrapping it in an array
        // here would turn '*' into an IP that matches nothing and silently trust nobody.
        // ONLY X_FORWARDED_FOR. This is not conservatism for its own sake — the other
        // three each broke production when trusted, and the finding needed none of them:
        //
        //   PORT  — took the site down 2026-08-28. cloudflared reaches nginx on loopback
        //           :8001, so a trusted X-Forwarded-Port makes getPort() return 8001 and
        //           EVERY generated URL becomes https://ep.claritasapp.com:8001/… . The
        //           login form then POSTs to a port the tunnel does not serve, the browser
        //           never lands back on a page holding its session, and the user is bounced
        //           to /login with "Your session has expired". Logins were broken for
        //           everyone until this was reverted.
        //   PROTO — would redirect-loop the site off the air. secure() already resolves
        //           true by itself (production emits HSTS, which SecurityHeaders only sets
        //           when secure() is true), so nginx sets the HTTPS var directly. Trusting
        //           the tunnel's plain-HTTP proto would make secure() false → ForceHttps
        //           301s to https → browser returns over https → loop.
        //   HOST  — same class of hazard: it feeds getHost(), which feeds URL generation
        //           and cookie scope. The host already resolves correctly without it.
        //
        // FOR is the whole point: real client IPs in security_audit_logs, working
        // ThreatDetector signals, and per-client rather than one global throttle bucket.
        // Do not add the others back to "complete the set".
        $middleware->trustProxies(
            at: (string) env('TRUSTED_PROXIES', '*'),
            headers: Request::HEADER_X_FORWARDED_FOR,
        );

        // Force HTTPS in production
        $middleware->prepend(\App\Http\Middleware\ForceHttps::class);

        // Global security headers on every response
        $middleware->prepend(\App\Http\Middleware\SecurityHeaders::class);

        // Scan every uploaded file for malware before any controller runs.
        // No-op on requests with no files; runs MalwareScanner heuristics
        // (and ClamAV if configured) on every UploadedFile otherwise.
        $middleware->append(\App\Http\Middleware\ScanUploadsForMalware::class);

        // Exempt the public AARF acknowledgement POST from CSRF verification.
        // This route is accessed via a token link (e.g. from email), often in a fresh
        // browser session where no CSRF token has been set yet.
        // The rental-return acknowledgement is NOT listed here: it is signed in-app on our
        // own device by a logged-in operator, so it has a session and a CSRF token like any
        // other form. Only the employee AARF is a cold email click.
        $middleware->validateCsrfTokens(except: [
            'aarf/*/acknowledge',
        ]);

    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Redirect to login with a fresh CSRF token when session has expired (419).
        // Laravel's prepareException() converts TokenMismatchException to HttpException(419)
        // before render callbacks run, so we must catch HttpException and check the status code.
        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\HttpException $e, $request) {
            if ($e->getStatusCode() === 419) {
                return redirect()->route('login')->with('warning', 'Your session has expired. Please log in again.');
            }
        });
    })->create();
