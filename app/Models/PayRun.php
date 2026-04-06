<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayRun extends Model
{
    protected $fillable = [
        'company', 'reference', 'title', 'year', 'month',
        'pay_date', 'period_start', 'period_end', 'status',
        'created_by', 'approved_by', 'approved_at',
        'total_gross', 'total_deductions', 'total_net', 'total_employer_cost',
        'notes',
    ];

    protected $casts = [
        'pay_date' => 'date',
        'period_start' => 'date',
        'period_end' => 'date',
        'approved_at' => 'datetime',
        'total_gross' => 'decimal:2',
        'total_deductions' => 'decimal:2',
        'total_net' => 'decimal:2',
        'total_employer_cost' => 'decimal:2',
    ];

    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    // ── Accessors (alias for view compatibility) ──────────────────────
    public function getTotalGrossPayAttribute(): float { return (float) $this->total_gross; }
    public function getTotalNetPayAttribute(): float   { return (float) $this->total_net; }

    public function statusBadge(): string
    {
        $class = match ($this->status) {
            'draft' => 'secondary',
            'processing' => 'info',
            'approved' => 'primary',
            'paid' => 'success',
            'cancelled' => 'danger',
            default => 'secondary',
        };
        return '<span class="badge bg-' . $class . '">' . ucfirst($this->status) . '</span>';
    }
}
