<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class OnboardingEditLog extends Model
{
    protected $fillable = [
        'onboarding_id',
        'edited_by_user_id',
        'edited_by_name',
        'edited_by_role',
        'sections_changed',
        'change_notes',
        'consent_required',
        'consent_token',
        'consent_token_expires_at',
        'consent_requested_at',
        'consent_sent_to_email',
        'acknowledged_by_user_id',
        'acknowledged_by_name',
        'acknowledged_at',
        'acknowledgement_notes',
    ];

    protected $casts = [
        'sections_changed'         => 'array',
        'consent_required'         => 'boolean',
        'consent_token_expires_at' => 'datetime',
        'consent_requested_at'     => 'datetime',
        'acknowledged_at'          => 'datetime',
    ];

    public function onboarding(): BelongsTo
    {
        return $this->belongsTo(Onboarding::class);
    }

    public function editedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'edited_by_user_id');
    }

    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by_user_id');
    }

    public function isAcknowledged(): bool
    {
        return !is_null($this->acknowledged_at);
    }

    public function isTokenExpired(): bool
    {
        return $this->consent_token_expires_at && $this->consent_token_expires_at->isPast();
    }

    public static function generateToken(): string
    {
        return Str::random(64);
    }
}
