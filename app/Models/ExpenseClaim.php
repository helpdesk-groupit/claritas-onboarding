<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExpenseClaim extends Model
{
    protected $fillable = [
        'employee_id', 'claim_number', 'title', 'event', 'year', 'month',
        'total_amount', 'total_gst', 'total_with_gst', 'item_count',
        'status', 'submitted_at', 'submission_deadline',
        'manager_id', 'manager_approved_by', 'manager_approved_at', 'manager_remarks',
        'hr_approved_by', 'hr_approved_at', 'hr_remarks',
        'released_at', 'released_by', 'release_remarks', 'correction_of_id',
        'payslip_id', 'pay_run_id', 'notes',
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
        'total_gst' => 'decimal:2',
        'total_with_gst' => 'decimal:2',
        'submitted_at' => 'datetime',
        'submission_deadline' => 'date',
        'manager_approved_at' => 'datetime',
        'hr_approved_at' => 'datetime',
        'released_at' => 'datetime',
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

    public function releasedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'released_by');
    }

    /** The original (rejected) claim this one is a correction of. */
    public function correctionOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'correction_of_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ExpenseClaimItem::class)->orderBy('expense_date');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(ExpenseClaimLog::class)->orderBy('created_at');
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

    /** Payable total — items not rejected by either the manager or HR. */
    public function approvedTotal(): float
    {
        return (float) $this->items
            ->where('manager_status', '!=', 'rejected')
            ->where('review_status', '!=', 'rejected')
            ->sum('total_with_gst');
    }

    /** Number of line items rejected at either stage (manager or HR). */
    public function rejectedItemCount(): int
    {
        return $this->items->filter(fn ($i) => $i->isRejected())->count();
    }

    /** True when one or more items were rejected — a partial approval. */
    public function hasRejectedItems(): bool
    {
        return $this->rejectedItemCount() > 0;
    }

    // ── Per-item approver roll-up ─────────────────────────────────────────

    /** Items still awaiting their assigned manager's decision. */
    public function managerPendingCount(): int
    {
        return $this->items->where('manager_status', 'pending')->count();
    }

    /** True when every item has had a manager decision (approved or rejected). */
    public function allItemsManagerDecided(): bool
    {
        return $this->items->isNotEmpty() && $this->managerPendingCount() === 0;
    }

    /** "3 / 5" style progress of manager decisions, for the status display. */
    public function managerProgress(): string
    {
        $total = $this->items->count();

        return ($total - $this->managerPendingCount()).' / '.$total;
    }

    /** Distinct approver (Employee) ids assigned across this claim's items. */
    public function approverIds(): array
    {
        return $this->items->pluck('approver_id')->filter()->unique()->values()->all();
    }

    /**
     * Aggregated automatic-check findings for the reviewer summary banner (#10):
     * failed intrinsic checks, over-cap items, and duplicates. OCR/ORS verification
     * is excluded (it runs on demand). Returns a list of human-readable strings.
     */
    public function reviewFlags(): array
    {
        $flags = [];
        foreach ($this->items as $item) {
            $label = '“'.\Illuminate\Support\Str::limit($item->description, 28).'”';
            foreach ($item->checks() as $c) {
                if (! $c['ok']) {
                    $flags[] = $label.': '.$c['label'].' — check failed';
                }
            }
            if (($cap = $item->capFlag()) && $cap['state'] === 'over') {
                $flags[] = $label.': over the '.$cap['period'].' cap (RM '.number_format($cap['used'], 2).' / RM '.number_format($cap['cap'], 2).')';
            }
            if ($dup = $item->duplicateFlag()) {
                $flags[] = $label.': '.$dup['message'];
            }
        }

        return $flags;
    }

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
        // Rejected claims are now terminal (frozen as history); corrections are filed as
        // a NEW claim, so only a draft is directly editable.
        return $this->status === 'draft';
    }

    public function isSubmittable(): bool
    {
        return $this->status === 'draft' && $this->item_count > 0;
    }

    /** True when the employee may file a correction of this rejected claim. */
    public function canCorrect(): bool
    {
        // Manager rejection → correct immediately. HR rejection → only after the manager
        // has released it back to the employee.
        return $this->status === 'manager_rejected'
            || ($this->status === 'hr_rejected' && $this->released_at !== null);
    }

    /** HR-rejected and still waiting for the approving manager to release it. */
    public function awaitingRelease(): bool
    {
        return $this->status === 'hr_rejected' && $this->released_at === null;
    }

    /**
     * Filesystem-safe PDF filename matching the original forms, e.g.
     * "ENSB-SE-20260430-Eisya Ereena_AMD Editorial_Claim_Apr_26.pdf".
     */
    public function pdfFilename(): string
    {
        $company = $this->employee->company ?? '';
        $prefix = null;
        foreach ((array) config('claims.file_prefixes', []) as $name => $px) {
            if ($name !== '' && stripos($company, $name) !== false) {
                $prefix = $px;
                break;
            }
        }
        if (! $prefix) {
            $initials = collect(preg_split('/\s+/', $company))
                ->map(fn ($w) => strtoupper(mb_substr(preg_replace('/[^A-Za-z]/', '', $w), 0, 1)))
                ->filter()->implode('');
            $prefix = ($initials ?: 'CLAIM').'-SE';
        }

        $batch = ($this->hr_approved_at ?? now())->copy()->endOfMonth()->format('Ymd');
        $name = $this->employee->full_name ?? 'Employee';
        $period = \Carbon\Carbon::create($this->year, $this->month)->format('M_y'); // Apr_26
        $event = trim((string) $this->event);
        $mid = ($event === '' || preg_match('/^general claim/i', $event)) ? 'Claim' : $event.'_Claim';

        $raw = "{$prefix}-{$batch}-{$name}_{$mid}_{$period}";
        $raw = preg_replace('/[\/\\\\:*?"<>|\r\n]+/', ' ', $raw);
        $raw = preg_replace('/\s+/', ' ', $raw);

        return trim($raw).'.pdf';
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
        $prefix = 'EC-'.$year.'-'.str_pad($month, 2, '0', STR_PAD_LEFT);
        $last = static::where('claim_number', 'like', $prefix.'-%')
            ->orderByDesc('claim_number')
            ->value('claim_number');

        $seq = $last ? ((int) substr($last, -4)) + 1 : 1;

        return $prefix.'-'.str_pad($seq, 4, '0', STR_PAD_LEFT);
    }
}
