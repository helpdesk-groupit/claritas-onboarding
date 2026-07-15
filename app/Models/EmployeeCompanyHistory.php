<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One "stint" of an employee at a company (see the migration for the full model).
 * ended_on = null means it is the current company.
 */
class EmployeeCompanyHistory extends Model
{
    protected $fillable = [
        'employee_id', 'company', 'office_location', 'started_on', 'ended_on', 'changed_by',
    ];

    protected $casts = [
        'started_on' => 'date',
        'ended_on' => 'date',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    /** True while this is the employee's current company. */
    public function isCurrent(): bool
    {
        return $this->ended_on === null;
    }
}
