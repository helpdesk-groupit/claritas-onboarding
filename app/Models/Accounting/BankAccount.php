<?php

namespace App\Models\Accounting;

use Illuminate\Database\Eloquent\Model;

class BankAccount extends Model
{
    protected $table = 'acc_bank_accounts';

    protected $fillable = [
        'company', 'account_name', 'account_number', 'bank_name', 'bank_branch',
        'swift_code', 'currency', 'opening_balance', 'opening_balance_date',
        'chart_of_account_id', 'is_active',
    ];

    protected $casts = [
        'opening_balance'      => 'decimal:2',
        'opening_balance_date' => 'date',
        'is_active'            => 'boolean',
    ];

    public function chartOfAccount() { return $this->belongsTo(ChartOfAccount::class, 'chart_of_account_id'); }
    public function transactions()   { return $this->hasMany(BankTransaction::class, 'bank_account_id')->latest('date'); }
    public function reconciliations(){ return $this->hasMany(BankReconciliation::class, 'bank_account_id'); }

    public function scopeActive($query) { return $query->where('is_active', true); }

    public function getCurrentBalanceAttribute(): float
    {
        $debits  = $this->transactions()->sum('debit');
        $credits = $this->transactions()->sum('credit');
        return $this->opening_balance + $debits - $credits;
    }
}
