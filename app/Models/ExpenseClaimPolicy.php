<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExpenseClaimPolicy extends Model
{
    protected $fillable = [
        'company', 'submission_deadline_day', 'require_manager_approval',
        'require_hr_approval', 'auto_approve_below', 'reminder_days_before',
        'gst_enabled', 'gst_rate', 'general_rules',
    ];

    protected $casts = [
        'require_manager_approval' => 'boolean',
        'require_hr_approval' => 'boolean',
        'auto_approve_below' => 'decimal:2',
        'gst_enabled' => 'boolean',
        'gst_rate' => 'decimal:2',
    ];

    /**
     * Get the policy for a given company (or default).
     */
    public static function forCompany(?string $company = null): self
    {
        if ($company) {
            $policy = static::where('company', $company)->first();
            if ($policy) return $policy;
        }

        return static::whereNull('company')->first()
            ?? new static([
                'submission_deadline_day' => 20,
                'require_manager_approval' => true,
                'require_hr_approval' => true,
                'gst_enabled' => true,
                'gst_rate' => 8.00,
                'reminder_days_before' => 3,
            ]);
    }
}
