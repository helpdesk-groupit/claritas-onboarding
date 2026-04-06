<?php

namespace App\Http\Middleware;

use App\Models\SecurityAuditLog;
use App\Services\ThreatDetector;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SecurityAuditMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        // Check for rapid-fire automated requests
        $rateAlert = ThreatDetector::checkRateAnomaly($request->ip());
        if ($rateAlert) {
            ThreatDetector::analyze('rate_anomaly', $rateAlert);
        }

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
        $context = [
            'user_id'    => $user?->id,
            'work_email' => $user?->work_email,
            'role'       => $user?->role,
            'url'        => $request->fullUrl(),
            'method'     => $request->method(),
            'ip_address' => $request->ip(),
            'details'    => 'Attempted to access restricted resource: ' . $request->method() . ' ' . $request->path(),
        ];

        SecurityAuditLog::record('unauthorized_access', $context);

        // Trigger real-time threat analysis
        ThreatDetector::analyze('unauthorized_access', $context);
    }
}
