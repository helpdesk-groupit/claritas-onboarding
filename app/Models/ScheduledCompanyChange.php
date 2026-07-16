<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A future-dated company move for an employee, awaiting its effective date (see the migration).
 * Applied by the `company:apply-scheduled` command, which reuses Employee::changeCompanyEffective
 * + CompanyAttributionService so the outcome is identical to an immediate move made on that day.
 */
class ScheduledCompanyChange extends Model
{
    protected $fillable = [
        'employee_id', 'company', 'office_location', 'effective_date', 'status',
        'scheduled_by', 'applied_at', 'cancelled_by', 'cancelled_at', 'note',
    ];

    protected $casts = [
        'effective_date' => 'date',
        'applied_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function scheduledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'scheduled_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /** Only changes still waiting to be applied. */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    /** Pending changes whose effective date has arrived (on/before the given date, default today). */
    public function scopeDue(Builder $query, $onDate = null): Builder
    {
        $date = $onDate ? \Carbon\Carbon::parse($onDate) : \Carbon\Carbon::today();

        return $query->where('status', 'pending')->whereDate('effective_date', '<=', $date->toDateString());
    }
}
