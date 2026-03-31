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
        // Exempt the public AARF acknowledgement POST from CSRF verification.
        // This route is accessed via a token link (e.g. from email), often in a fresh
        // browser session where no CSRF token has been set yet.
        $middleware->validateCsrfTokens(except: [
            'aarf/*/acknowledge',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();