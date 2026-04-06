<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExpenseClaimItem extends Model
{
    protected $fillable = [
        'expense_claim_id', 'expense_category_id', 'expense_date',
        'description', 'project_client', 'amount', 'gst_amount',
        'total_with_gst', 'receipt_path', 'is_locked', 'remarks',
    ];

    protected $casts = [
        'expense_date' => 'date',
        'amount' => 'decimal:2',
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
