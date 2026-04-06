<?php

namespace App\Models\Accounting;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class BankReconciliation extends Model
{
    protected $table = 'acc_bank_reconciliations';

    protected $fillable = [
        'bank_account_id', 'statement_date', 'statement_balance',
        'reconciled_balance', 'difference', 'status', 'completed_by', 'completed_at',
    ];

    protected $casts = [
        'statement_date'    => 'date',
        'statement_balance' => 'decimal:2',
        'reconciled_balance'=> 'decimal:2',
        'difference'        => 'decimal:2',
        'completed_at'      => 'datetime',
    ];

    public function bankAccount()    { return $this->belongsTo(BankAccount::class, 'bank_account_id'); }
    public function completedByUser(){ return $this->belongsTo(User::class, 'completed_by'); }
    public function transactions()   { return $this->hasMany(BankTransaction::class, 'reconciliation_id'); }
}
