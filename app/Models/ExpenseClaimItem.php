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
        'review_status',
    ];

    /** True when an approver has rejected this individual line item. */
    public function isRejected(): bool
    {
        return $this->review_status === 'rejected';
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
