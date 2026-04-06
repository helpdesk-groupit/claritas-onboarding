<?php

namespace App\Models\Accounting;

use Illuminate\Database\Eloquent\Model;

class BudgetLine extends Model
{
    protected $table = 'acc_budget_lines';

    protected $fillable = ['budget_id', 'account_id', 'fiscal_period_id', 'amount'];

    protected $casts = ['amount' => 'decimal:2'];

    public function budget()       { return $this->belongsTo(Budget::class, 'budget_id'); }
    public function account()      { return $this->belongsTo(ChartOfAccount::class, 'account_id'); }
    public function fiscalPeriod() { return $this->belongsTo(FiscalPeriod::class, 'fiscal_period_id'); }
}
