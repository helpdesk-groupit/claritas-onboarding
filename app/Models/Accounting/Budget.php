<?php

namespace App\Models\Accounting;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class Budget extends Model
{
    protected $table = 'acc_budgets';

    protected $fillable = [
        'company', 'name', 'fiscal_year_id', 'status', 'description',
        'created_by', 'approved_by',
    ];

    public function fiscalYear()     { return $this->belongsTo(FiscalYear::class, 'fiscal_year_id'); }
    public function lines()          { return $this->hasMany(BudgetLine::class, 'budget_id'); }
    public function createdByUser()  { return $this->belongsTo(User::class, 'created_by'); }
    public function approvedByUser() { return $this->belongsTo(User::class, 'approved_by'); }
}
