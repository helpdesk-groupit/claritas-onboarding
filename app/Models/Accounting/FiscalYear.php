<?php

namespace App\Models\Accounting;

use Illuminate\Database\Eloquent\Model;

class FiscalYear extends Model
{
    protected $table = 'acc_fiscal_years';

    protected $fillable = ['company', 'name', 'start_date', 'end_date', 'status'];

    protected $casts = [
        'start_date' => 'date',
        'end_date'   => 'date',
    ];

    public function periods()
    {
        return $this->hasMany(FiscalPeriod::class, 'fiscal_year_id')->orderBy('period_number');
    }

    public function budgets()
    {
        return $this->hasMany(Budget::class, 'fiscal_year_id');
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }
}
