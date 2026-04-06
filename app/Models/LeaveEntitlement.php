<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveEntitlement extends Model
{
    protected $fillable = [
        'leave_type_id', 'company', 'min_tenure_months',
        'max_tenure_months', 'entitled_days', 'carry_forward_limit',
    ];

    protected $casts = [
        'entitled_days' => 'decimal:1',
        'carry_forward_limit' => 'decimal:1',
    ];

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }
}
