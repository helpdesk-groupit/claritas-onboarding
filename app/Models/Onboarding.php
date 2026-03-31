<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Onboarding extends Model
{
    protected $fillable = [
        'status',
        'is_expired',           // true once moved to employee table
        'hr_email', 'it_email',
        'hr_emails', 'it_emails',
        'manual_aarf_path',
        'calendar_invite_sent', 'welcome_email_sent',
        'assigned_pic_user_id',
        'asset_preparation_status',
        'work_email_status',
        'invite_token', 'invite_email', 'invite_expires_at', 'invite_submitted',
    ];

    protected $casts = [
        'hr_emails'             => 'array',
        'it_emails'             => 'array',
        'calendar_invite_sent'  => 'boolean',
        'welcome_email_sent'    => 'boolean',
        'is_expired'            => 'boolean',
        'invite_submitted'      => 'boolean',
        'invite_expires_at'     => 'datetime',
    ];

    public function personalDetail(): HasOne   { return $this->hasOne(PersonalDetail::class); }
    public function workDetail(): HasOne       { return $this->hasOne(WorkDetail::class); }
    public function assetProvisioning(): HasOne { return $this->hasOne(AssetProvisioning::class); }
    public function assetAssignments(): HasMany { return $this->hasMany(AssetAssignment::class); }
    public function aarf(): HasOne             { return $this->hasOne(Aarf::class); }
    public function employee(): HasOne         { return $this->hasOne(Employee::class); }
    public function offboarding(): HasOne      { return $this->hasOne(Offboarding::class); }
    public function itTasks(): HasMany         { return $this->hasMany(ItTask::class); }
    public function editLogs(): HasMany        { return $this->hasMany(\App\Models\OnboardingEditLog::class)->latest(); }
    public function assignedPic(): BelongsTo   { return $this->belongsTo(User::class, 'assigned_pic_user_id'); }

    public static function generateAarfReference(): string
    {
        return 'AARF-' . strtoupper(Str::random(8)) . '-' . date('Y');
    }
}