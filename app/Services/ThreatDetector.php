<?php

namespace App\Services;

use App\Mail\SuspiciousActivityAlert;
use App\Models\SecurityAuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Real-time detection and alerting for suspicious activity patterns.
 *
 * Detects:
 * - Brute-force login attempts (≥5 failures in 10 min from same IP)
 * - Account lockouts
 * - Privilege escalation attempts (unauthorized admin access)
 * - Session anomalies (IP change mid-session)
 * - Rapid-fire requests indicating automated attacks
 * - Off-hours access to sensitive areas
 *
 * Alerts IT Managers and Superadmins immediately via email.
 */
class ThreatDetector
{
    // Thresholds (configurable via env)
    private const LOGIN_FAIL_THRESHOLD   = 5;  // failures per IP in window
    private const LOGIN_FAIL_WINDOW      = 600; // seconds (10 min)
    private const RAPID_REQUEST_THRESHOLD = 60; // requests per minute
    private const OFF_HOURS_START         = 22; // 10 PM
    private const OFF_HOURS_END           = 6;  // 6 AM

    /**
     * Check for suspicious patterns after a security event is logged.
     * Call this from SecurityAuditMiddleware or controllers.
     */
    public static function analyze(string $eventType, array $context = []): void
    {
        $alerts = [];

        switch ($eventType) {
            case 'failed_login':
                $alerts = array_merge($alerts, self::checkBruteForce($context));
                break;

            case 'account_locked':
                $alerts[] = self::buildAlert(
                    'critical',
                    'Account Locked Out',
                    "Account {$context['work_email']} has been locked after repeated failed login attempts.",
                    $context
                );
                break;

            case 'unauthorized_access':
                $alerts = array_merge($alerts, self::checkPrivilegeEscalation($context));
                break;

            case 'session_ip_mismatch':
                $alerts[] = self::buildAlert(
                    'high',
                    'Session IP Anomaly',
                    "User {$context['work_email']} session detected from a different IP address — possible session hijacking.",
                    $context
                );
                break;
        }

        // Check off-hours access for admin/sensitive areas
        if (self::isOffHours() && self::isSensitiveUrl($context['url'] ?? '')) {
            $alerts[] = self::buildAlert(
                'medium',
                'Off-Hours Sensitive Access',
                "Access to sensitive area during off-hours by {$context['work_email']}.",
                $context
            );
        }

        // Send alerts
        foreach ($alerts as $alert) {
            self::dispatchAlert($alert);
        }
    }

    /**
     * Track login failures and detect brute-force patterns.
     */
    private static function checkBruteForce(array $context): array
    {
        $ip    = $context['ip_address'] ?? 'unknown';
        $key   = "login_failures:{$ip}";
        $count = (int) Cache::get($key, 0);
        $count++;
        Cache::put($key, $count, self::LOGIN_FAIL_WINDOW);

        $alerts = [];

        if ($count === self::LOGIN_FAIL_THRESHOLD) {
            $alerts[] = self::buildAlert(
                'high',
                'Brute-Force Attack Detected',
                "IP {$ip} has had {$count} failed login attempts in the last " . (self::LOGIN_FAIL_WINDOW / 60) . " minutes.",
                $context
            );
        }

        if ($count === self::LOGIN_FAIL_THRESHOLD * 2) {
            $alerts[] = self::buildAlert(
                'critical',
                'Sustained Brute-Force Attack',
                "IP {$ip} has had {$count} failed login attempts — attack is ongoing. Consider IP blocking.",
                $context
            );
        }

        return $alerts;
    }

    /**
     * Detect privilege escalation patterns (multiple 403s).
     */
    private static function checkPrivilegeEscalation(array $context): array
    {
        $userId = $context['user_id'] ?? null;
        if (!$userId) return [];

        $key   = "priv_esc_attempts:{$userId}";
        $count = (int) Cache::get($key, 0);
        $count++;
        Cache::put($key, $count, 600); // 10 min window

        $alerts = [];

        if ($count >= 3) {
            $alerts[] = self::buildAlert(
                'high',
                'Possible Privilege Escalation',
                "User {$context['work_email']} (role: {$context['role']}) has attempted {$count} unauthorized actions in 10 minutes.",
                $context
            );
            // Reset counter to avoid spam
            Cache::forget($key);
        }

        return $alerts;
    }

    /**
     * Check for rapid-fire automated requests.
     */
    public static function checkRateAnomaly(string $ip): ?array
    {
        $key   = "request_rate:{$ip}";
        $count = (int) Cache::get($key, 0);
        $count++;
        Cache::put($key, $count, 60);

        if ($count === self::RAPID_REQUEST_THRESHOLD) {
            return self::buildAlert(
                'high',
                'Automated Attack Suspected',
                "IP {$ip} has made {$count} requests in the last minute — possible automated scanning/attack.",
                ['ip_address' => $ip]
            );
        }

        return null;
    }

    private static function isOffHours(): bool
    {
        $hour = (int) now()->setTimezone('Asia/Kuala_Lumpur')->format('G');
        return $hour >= self::OFF_HOURS_START || $hour < self::OFF_HOURS_END;
    }

    private static function isSensitiveUrl(string $url): bool
    {
        $sensitivePatterns = [
            '/superadmin/',
            '/role-management',
            '/accounting/',
            '/payroll/',
            '/employee/*/edit',
        ];

        foreach ($sensitivePatterns as $pattern) {
            if (str_contains($url, str_replace('*', '', $pattern))) {
                return true;
            }
        }

        return false;
    }

    private static function buildAlert(string $severity, string $title, string $description, array $context): array
    {
        return [
            'severity'    => $severity,
            'title'       => $title,
            'description' => $description,
            'ip_address'  => $context['ip_address'] ?? null,
            'work_email'  => $context['work_email'] ?? null,
            'role'        => $context['role'] ?? null,
            'url'         => $context['url'] ?? null,
            'timestamp'   => now()->setTimezone('Asia/Kuala_Lumpur')->toIso8601String(),
        ];
    }

    private static function dispatchAlert(array $alert): void
    {
        // Log to integrity-protected audit log
        LogIntegrity::write('security', $alert['severity'], $alert['title'], $alert);

        // Also log via standard channel
        Log::channel('stack')->warning("SECURITY ALERT [{$alert['severity']}]: {$alert['title']}", $alert);

        // Record in security audit log
        SecurityAuditLog::record('threat_alert', [
            'ip_address' => $alert['ip_address'],
            'work_email' => $alert['work_email'],
            'role'       => $alert['role'],
            'url'        => $alert['url'],
            'details'    => "[{$alert['severity']}] {$alert['title']}: {$alert['description']}",
        ]);

        // Deduplicate: don't send the same alert type for the same IP within 15 minutes
        $dedupeKey = "alert_sent:" . md5($alert['title'] . ($alert['ip_address'] ?? '') . ($alert['work_email'] ?? ''));
        if (Cache::has($dedupeKey)) {
            return;
        }
        Cache::put($dedupeKey, true, 900); // 15 minutes

        // Send immediate email to IT Managers and Superadmins
        $recipients = User::whereIn('role', ['it_manager', 'superadmin'])
            ->where('is_active', true)
            ->pluck('work_email')
            ->filter()
            ->unique()
            ->values();

        foreach ($recipients as $email) {
            try {
                Mail::to($email)->send(new SuspiciousActivityAlert($alert));
            } catch (\Throwable $e) {
                Log::warning("ThreatDetector: failed to send alert to {$email}: " . $e->getMessage());
            }
        }
    }
}
