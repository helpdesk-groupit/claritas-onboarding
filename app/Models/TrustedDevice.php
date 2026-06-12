<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A remembered browser/device that may skip the 2FA challenge on login.
 * See App\Services\TrustedDeviceService for issue/verify/revoke logic.
 */
class TrustedDevice extends Model
{
    protected $fillable = [
        'user_id', 'selector', 'validator_hash', 'device_label',
        'user_agent', 'last_ip', 'last_country', 'last_used_at', 'expires_at',
    ];

    protected $hidden = ['selector', 'validator_hash'];

    protected $casts = [
        'last_used_at' => 'datetime',
        'expires_at'   => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
