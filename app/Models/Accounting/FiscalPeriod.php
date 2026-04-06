<?php

namespace App\Models\Accounting;

use Illuminate\Database\Eloquent\Model;

class FiscalPeriod extends Model
{
    protected $table = 'acc_fiscal_periods';

    protected $fillable = ['fiscal_year_id', 'period_number', 'name', 'start_date', 'end_date', 'status'];

    protected $casts = [
        'start_date' => 'date',
        'end_date'   => 'date',
    ];

    public function fiscalYear()
    {
        return $this->belongsTo(FiscalYear::class, 'fiscal_year_id');
    }

    public function budgetLines()
    {
        return $this->hasMany(BudgetLine::class, 'fiscal_period_id');
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }
}
