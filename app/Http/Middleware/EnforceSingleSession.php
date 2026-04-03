<?php

namespace App\Http\Middleware;

use App\Models\SecurityAuditLog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EnforceSingleSession
{
    public function handle(Request $request, Closure $next)
    {
        if (Auth::check()) {
            // Fetch a fresh copy from DB so we always compare against the latest token
            $user = \App\Models\User::find(Auth::id());

            $dbToken      = $user?->session_token;
            $sessionToken = session('_single_session_token');

            // If DB has a token and this session's token doesn't match → newer login elsewhere
            if ($dbToken && $sessionToken !== $dbToken) {
                SecurityAuditLog::record('session_hijack', [
                    'user_id'    => $user?->id,
                    'work_email' => $user?->work_email,
                    'role'       => $user?->role,
                    'ip_address' => $request->ip(),
                    'url'        => $request->fullUrl(),
                    'details'    => 'Session invalidated: account signed in from another device or browser.',
                ]);

                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return redirect()->route('login')
                    ->withErrors(['work_email' => 'You have been logged out because your account was signed in from another device or browser.']);
            }
        }

        return $next($request);
    }
}
