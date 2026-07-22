<?php

namespace App\Models;

use App\Services\ClaimRulesService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExpenseClaimItem extends Model
{
    protected $fillable = [
        'expense_claim_id', 'expense_category_id', 'expense_date',
        'description', 'project_client', 'amount', 'quantity', 'unit', 'rate_applied',
        'gst_amount', 'total_with_gst', 'receipt_path', 'receipt_paths', 'receipt_hash', 'supporting_paths', 'ocr_details', 'is_locked', 'remarks',
        'review_status', 'mileage_destination', 'mileage_origin', 'approver_id', 'manager_status', 'manager_remarks', 'reject_comment',
    ];

    /** The manager assigned to approve this specific item (per-item routing). */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'approver_id');
    }

    /** Rejected at EITHER stage (manager or HR) — used for struck-through display. */
    public function isRejected(): bool
    {
        return $this->manager_status === 'rejected' || $this->review_status === 'rejected';
    }

    /** Rejected by the item's assigned manager (stage 1). */
    public function isManagerRejected(): bool
    {
        return $this->manager_status === 'rejected';
    }

    /** Still awaiting the assigned manager's decision. */
    public function isManagerPending(): bool
    {
        return $this->manager_status === 'pending';
    }

    /** This item gets paid only if its manager approved it and HR didn't reject it. */
    public function isPayable(): bool
    {
        return $this->manager_status !== 'rejected' && $this->review_status !== 'rejected';
    }

    /** The reason shown to the employee for a rejection, whichever stage rejected it. */
    public function rejectionReason(): ?string
    {
        if ($this->manager_status === 'rejected') {
            return $this->manager_remarks;
        }
        if ($this->review_status === 'rejected') {
            return $this->remarks;
        }

        return null;
    }

    /** True when this line was claimed by mileage (distance) rather than a receipt. */
    public function isMileage(): bool
    {
        return ! empty($this->mileage_destination) || $this->unit === 'km';
    }

    /**
     * True when this item's category requires a receipt but none is attached yet.
     * Mileage lines are exempt (the distance is the evidence). Used to let drafts be
     * saved without a receipt while still blocking submission until one is added.
     */
    public function needsReceipt(): bool
    {
        $cat = $this->category;
        if (! $cat || ! $cat->requires_receipt) {
            return false;
        }
        $mileageGl = config('claims.mileage.gl_code');
        if ($this->isMileage() || ($mileageGl && $cat->gl_code === $mileageGl)) {
            return false;
        }

        return empty($this->receipt_path);
    }

    /**
     * Intrinsic, no-API sanity checks for the reviewer — each is
     * ['label' => ..., 'ok' => bool, 'detail' => ?string].
     * (Receipt-amount OCR and mileage-distance checks are done on demand via the
     * verify endpoint, since they hit external APIs.)
     */
    public function checks(): array
    {
        $out = [];

        // Total = amount + GST
        $mathOk = abs(((float) $this->amount + (float) $this->gst_amount) - (float) $this->total_with_gst) < 0.01;
        $out[] = ['label' => 'Total = amount + GST', 'ok' => $mathOk];

        // Computed amount matches quantity × rate (mileage / per-day; OT uses bands, skip).
        // Mileage may be voluntarily UNDER-claimed (claim less than km × rate is allowed and
        // only soft-warned on entry), so for km the check passes as long as the amount does
        // not EXCEED the calculated figure — only an over-claim is a genuine mismatch.
        if ($this->quantity !== null && $this->rate_applied !== null && $this->unit && $this->unit !== 'hour') {
            $expected = round((float) $this->quantity * (float) $this->rate_applied, 2);
            $isKm = $this->unit === 'km';
            $out[] = [
                'label' => $isKm ? 'Amount ≤ km × rate' : 'Amount = '.$this->unit.' × rate',
                'ok' => $isKm
                    ? ((float) $this->amount <= $expected + 0.01)
                    : (abs($expected - (float) $this->amount) < 0.01),
                'detail' => number_format($this->quantity, 2).' × '.number_format($this->rate_applied, 2).' = '.number_format($expected, 2),
            ];
        }

        // Receipt attached when the category requires one (mileage claims need none)
        if ($this->category && $this->category->requires_receipt && ! $this->isMileage()) {
            $out[] = ['label' => 'Receipt attached', 'ok' => ! empty($this->receipt_path)];
        }

        return $out;
    }

    /**
     * Spending-cap status for this item's category & period (#3): 'over' / 'near'
     * (>=90%) / null when uncapped or comfortably within. Reuses the rules engine.
     *
     * @return array{state:string, used:float, cap:float, period:string}|null
     */
    public function capFlag(): ?array
    {
        $employee = $this->claim?->employee;
        $category = $this->category;
        if (! $employee || ! $category) {
            return null;
        }

        $limit = ClaimRulesService::effectiveLimit($category, $employee);
        if (! $limit) {
            return null; // uncapped category
        }

        $used = ClaimRulesService::usedInPeriod($employee, $category, $this->expense_date, $limit['period']);
        $cap = (float) $limit['amount'];

        if ($used > $cap + 0.001) {
            $state = 'over';
        } elseif ($used >= $cap * 0.9) {
            $state = 'near';
        } else {
            return null; // within limits — no flag
        }

        return ['state' => $state, 'used' => $used, 'cap' => $cap, 'period' => $limit['period']];
    }

    /**
     * Duplicate flag (#4): the same receipt used elsewhere (across ALL employees —
     * catches two people claiming one receipt), or an identical date+amount+
     * description line on another of this employee's claims. Returns a message or null.
     */
    /** @return array{message:string, item_id:int}|null */
    public function duplicateFlag(): ?array
    {
        // Claims that should NEVER count as a duplicate source:
        //  - dead claims (rejected/cancelled): void, not real spend; a correction
        //    copies the original's receipt_hash + line data, so the rejected
        //    original would otherwise raise a false "same receipt" flag (#12a).
        //  - this item's own correction lineage (the report it corrects, plus any
        //    sibling corrections of the same original).
        $deadStatuses = ['manager_rejected', 'hr_rejected', 'cancelled'];
        $lineageClaimIds = $this->correctionLineageClaimIds();

        $excludeDead = function ($q) use ($deadStatuses, $lineageClaimIds) {
            $q->whereNotIn('status', $deadStatuses);
            if (! empty($lineageClaimIds)) {
                $q->whereNotIn('id', $lineageClaimIds);
            }
        };

        if ($this->receipt_hash) {
            $dupReceipt = static::where('receipt_hash', $this->receipt_hash)
                ->where('id', '!=', $this->id)
                ->whereHas('claim', $excludeDead)
                ->with('claim')
                ->first();
            if ($dupReceipt) {
                return ['message' => 'Same receipt as '.($dupReceipt->claim->claim_number ?? 'another claim'), 'item_id' => $dupReceipt->id];
            }
        }

        $employeeId = $this->claim?->employee_id;
        if ($employeeId) {
            $dupLine = static::where('id', '!=', $this->id)
                ->where('expense_date', $this->expense_date)
                ->where('amount', $this->amount)
                ->where('description', $this->description)
                ->whereHas('claim', function ($q) use ($employeeId, $excludeDead) {
                    $q->where('employee_id', $employeeId);
                    $excludeDead($q);
                })
                ->with('claim')
                ->first();
            if ($dupLine) {
                return ['message' => 'Possible duplicate of '.($dupLine->claim->claim_number ?? 'another claim'), 'item_id' => $dupLine->id];
            }
        }

        return null;
    }

    /**
     * Claim ids in this item's correction lineage — the original report this claim
     * corrects (walking up correction_of_id), plus every correction that descends
     * from the same root. Used to keep dedup from flagging a claim against the very
     * report it supersedes.
     *
     * @return int[]
     */
    protected function correctionLineageClaimIds(): array
    {
        $claim = $this->claim;
        if (! $claim) {
            return [];
        }

        // Walk up to the root of the correction chain.
        $root = $claim;
        $guard = 0;
        while ($root->correction_of_id && $guard++ < 20) {
            $parent = ExpenseClaim::find($root->correction_of_id);
            if (! $parent) {
                break;
            }
            $root = $parent;
        }

        // Collect the root and all descendants (corrections of corrections).
        $ids = [$root->id];
        $frontier = [$root->id];
        $guard = 0;
        while (! empty($frontier) && $guard++ < 50) {
            $children = ExpenseClaim::whereIn('correction_of_id', $frontier)->pluck('id')->all();
            $children = array_values(array_diff($children, $ids));
            if (empty($children)) {
                break;
            }
            $ids = array_merge($ids, $children);
            $frontier = $children;
        }

        return $ids;
    }

    protected $casts = [
        'expense_date' => 'date',
        'amount' => 'decimal:2',
        'quantity' => 'decimal:2',
        'rate_applied' => 'decimal:2',
        'gst_amount' => 'decimal:2',
        'total_with_gst' => 'decimal:2',
        'is_locked' => 'boolean',
        'receipt_paths' => 'array',
        'supporting_paths' => 'array',
        'ocr_details' => 'array',
    ];

    public function claim(): BelongsTo
    {
        return $this->belongsTo(ExpenseClaim::class, 'expense_claim_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    public function attachmentPaths(): array
    {
        $paths = array_filter((array) $this->receipt_paths);
        if ($this->receipt_path) {
            array_unshift($paths, $this->receipt_path);
        }

        return array_values(array_unique($paths));
    }

    /** Optional, non-receipt supporting documents attached to this item. */
    public function supportingPaths(): array
    {
        return array_values(array_filter((array) $this->supporting_paths));
    }
}
