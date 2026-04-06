<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkSchedule extends Model
{
    protected $fillable = [
        'company', 'name', 'start_time', 'end_time',
        'break_start', 'break_end', 'work_hours_per_day',
        'working_days', 'is_default', 'is_active',
    ];

    protected $casts = [
        'working_days' => 'array',
        'work_hours_per_day' => 'decimal:2',
        'is_default' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function attendanceRecords(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class);
    }
}
