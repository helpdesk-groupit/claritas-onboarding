<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SecurityAuditLog extends Model
{
    protected $fillable = [
        'user_id', 'work_email', 'role',
        'event_type', 'url', 'method', 'ip_address', 'details', 'emailed',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Log a security event from any context (middleware, controller, command).
     */
    public static function record(string $eventType, array $context = []): void
    {
        try {
            static::create([
                'user_id'    => $context['user_id']    ?? null,
                'work_email' => $context['work_email'] ?? null,
                'role'       => $context['role']       ?? null,
                'event_type' => $eventType,
                'url'        => $context['url']        ?? null,
                'method'     => $context['method']     ?? null,
                'ip_address' => $context['ip_address'] ?? null,
                'details'    => $context['details']    ?? null,
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('SecurityAuditLog::record failed: ' . $e->getMessage());
        }
    }
}
