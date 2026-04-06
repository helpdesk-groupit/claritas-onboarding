<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveBalance extends Model
{
    protected $fillable = [
        'employee_id', 'leave_type_id', 'year',
        'entitled', 'taken', 'carry_forward', 'adjustment',
    ];

    protected $casts = [
        'entitled' => 'decimal:1',
        'taken' => 'decimal:1',
        'carry_forward' => 'decimal:1',
        'adjustment' => 'decimal:1',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function getAvailableAttribute(): float
    {
        return (float) $this->entitled + (float) $this->carry_forward + (float) $this->adjustment - (float) $this->taken;
    }
}
