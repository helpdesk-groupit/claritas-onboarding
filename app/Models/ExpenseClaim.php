<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExpenseClaim extends Model
{
    protected $fillable = [
        'employee_id', 'claim_number', 'title', 'year', 'month',
        'total_amount', 'total_gst', 'total_with_gst', 'item_count',
        'status', 'submitted_at', 'submission_deadline',
        'manager_id', 'manager_approved_by', 'manager_approved_at', 'manager_remarks',
        'hr_approved_by', 'hr_approved_at', 'hr_remarks',
        'payslip_id', 'pay_run_id', 'notes',
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
        'total_gst' => 'decimal:2',
        'total_with_gst' => 'decimal:2',
        'submitted_at' => 'date',
        'submission_deadline' => 'date',
        'manager_approved_at' => 'datetime',
        'hr_approved_at' => 'datetime',
    ];

    // ── Relationships ────────────────────────────────────────────────────

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'manager_id');
    }

    public function managerApprover(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'manager_approved_by');
    }

    public function hrApprover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'hr_approved_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ExpenseClaimItem::class)->orderBy('expense_date');
    }

    public function payslip(): BelongsTo
    {
        return $this->belongsTo(Payslip::class);
    }

    public function payRun(): BelongsTo
    {
        return $this->belongsTo(PayRun::class);
    }

    // ── Scopes ───────────────────────────────────────────────────────────

    public function scopePending($query)
    {
        return $query->where('status', 'submitted');
    }

    public function scopeManagerPending($query)
    {
        return $query->where('status', 'submitted');
    }

    public function scopeHrPending($query)
    {
        return $query->where('status', 'manager_approved');
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    public function recalculateTotals(): void
    {
        $items = $this->items()->get();
        $this->total_amount = $items->sum('amount');
        $this->total_gst = $items->sum('gst_amount');
        $this->total_with_gst = $items->sum('total_with_gst');
        $this->item_count = $items->count();
        $this->save();
    }

    public function isEditable(): bool
    {
        return in_array($this->status, ['draft', 'manager_rejected', 'hr_rejected']);
    }

    public function isSubmittable(): bool
    {
        return in_array($this->status, ['draft', 'manager_rejected', 'hr_rejected'])
            && $this->item_count > 0;
    }

    /**
     * @return array{class: string, label: string}
     */
    public function statusBadge(): array
    {
        $class = match ($this->status) {
            'draft' => 'secondary',
            'submitted' => 'info',
            'manager_approved' => 'primary',
            'manager_rejected' => 'danger',
            'hr_approved' => 'success',
            'hr_rejected' => 'danger',
            'paid' => 'success',
            'cancelled' => 'dark',
            default => 'secondary',
        };
        $label = match ($this->status) {
            'draft' => 'Draft',
            'submitted' => 'Pending Manager',
            'manager_approved' => 'Manager Approved',
            'manager_rejected' => 'Manager Rejected',
            'hr_approved' => 'HR Approved',
            'hr_rejected' => 'HR Rejected',
            'paid' => 'Paid',
            'cancelled' => 'Cancelled',
            default => ucfirst($this->status),
        };
        return ['class' => $class, 'label' => $label];
    }

    /**
     * Generate next claim number: EC-YYYY-MM-NNNN
     */
    public static function generateClaimNumber(int $year, int $month): string
    {
        $prefix = 'EC-' . $year . '-' . str_pad($month, 2, '0', STR_PAD_LEFT);
        $last = static::where('claim_number', 'like', $prefix . '-%')
            ->orderByDesc('claim_number')
            ->value('claim_number');

        $seq = $last ? ((int) substr($last, -4)) + 1 : 1;

        return $prefix . '-' . str_pad($seq, 4, '0', STR_PAD_LEFT);
    }
}
