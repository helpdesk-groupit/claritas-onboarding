<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExpenseClaimItem extends Model
{
    protected $fillable = [
        'expense_claim_id', 'expense_category_id', 'expense_date',
        'description', 'project_client', 'amount', 'quantity', 'unit', 'rate_applied',
        'gst_amount', 'total_with_gst', 'receipt_path', 'receipt_hash', 'is_locked', 'remarks',
        'review_status', 'mileage_destination',
    ];

    /** True when an approver has rejected this individual line item. */
    public function isRejected(): bool
    {
        return $this->review_status === 'rejected';
    }

    /** True when this line was claimed by mileage (distance) rather than a receipt. */
    public function isMileage(): bool
    {
        return ! empty($this->mileage_destination) || $this->unit === 'km';
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

        // Computed amount matches quantity × rate (mileage / per-day; OT uses bands, skip)
        if ($this->quantity !== null && $this->rate_applied !== null && $this->unit && $this->unit !== 'hour') {
            $expected = round((float) $this->quantity * (float) $this->rate_applied, 2);
            $out[] = [
                'label' => $this->unit === 'km' ? 'Amount = km × rate' : 'Amount = '.$this->unit.' × rate',
                'ok' => abs($expected - (float) $this->amount) < 0.01,
                'detail' => number_format($this->quantity, 2).' × '.number_format($this->rate_applied, 2).' = '.number_format($expected, 2),
            ];
        }

        // Receipt attached when the category requires one (mileage claims need none)
        if ($this->category && $this->category->requires_receipt && ! $this->isMileage()) {
            $out[] = ['label' => 'Receipt attached', 'ok' => ! empty($this->receipt_path)];
        }

        return $out;
    }

    protected $casts = [
        'expense_date' => 'date',
        'amount' => 'decimal:2',
        'quantity' => 'decimal:2',
        'rate_applied' => 'decimal:2',
        'gst_amount' => 'decimal:2',
        'total_with_gst' => 'decimal:2',
        'is_locked' => 'boolean',
    ];

    public function claim(): BelongsTo
    {
        return $this->belongsTo(ExpenseClaim::class, 'expense_claim_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }
}
