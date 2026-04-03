<?php

namespace App\Http\Middleware;

use App\Models\SecurityAuditLog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SecurityAuditMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $response = null;

        try {
            $response = $next($request);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            if ($e->getStatusCode() === 403) {
                $this->log403($request);
            }
            throw $e;
        }

        // Also catch 403 responses (not thrown as exceptions)
        if ($response && $response->getStatusCode() === 403) {
            $this->log403($request);
        }

        return $response;
    }

    private function log403(Request $request): void
    {
        $user = Auth::user();
        SecurityAuditLog::record('unauthorized_access', [
            'user_id'    => $user?->id,
            'work_email' => $user?->work_email,
            'role'       => $user?->role,
            'url'        => $request->fullUrl(),
            'method'     => $request->method(),
            'ip_address' => $request->ip(),
            'details'    => 'Attempted to access restricted resource: ' . $request->method() . ' ' . $request->path(),
        ]);
    }
}
