<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

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
