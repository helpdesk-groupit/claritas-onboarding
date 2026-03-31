<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use App\Models\User;

class AuthServiceProvider extends ServiceProvider
{
    protected $policies = [];

    /**
     * Register the custom user provider BEFORE boot so it's available
     * when Laravel resolves the auth guard on the first request.
     */
    public function register(): void
    {
        Auth::provider('work_email_eloquent', function ($app, array $config) {
            return new WorkEmailUserProvider(
                $app['hash'],
                $config['model']
            );
        });
    }

    public function boot(): void
    {
        // ── Gate definitions ──────────────────────────────────────────────
        Gate::define('isHr', function (User $user) {
            return $user->isHr() || $user->isIt();
        });

        // ── Customise the password reset URL to pass work_email ───────────
        ResetPassword::createUrlUsing(function ($notifiable, $token) {
            return url("/reset-password/{$token}?email=" . urlencode($notifiable->work_email));
        });
    }
}