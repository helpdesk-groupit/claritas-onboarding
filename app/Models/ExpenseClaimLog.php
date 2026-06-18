<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExpenseClaimLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['expense_claim_id', 'action', 'actor_id', 'actor_name', 'detail'];

    protected $casts = ['created_at' => 'datetime'];

    public function claim(): BelongsTo
    {
        return $this->belongsTo(ExpenseClaim::class, 'expense_claim_id');
    }

    /** Bootstrap badge colour for the action. */
    public function badgeClass(): string
    {
        return match (true) {
            str_contains($this->action, 'rejected') => 'danger',
            str_contains($this->action, 'approved') => 'success',
            $this->action === 'submitted' => 'info',
            default => 'secondary',
        };
    }

    /** Human label for the action. */
    public function label(): string
    {
        return ucwords(str_replace('_', ' ', $this->action));
    }
}
