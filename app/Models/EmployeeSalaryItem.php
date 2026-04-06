<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeSalaryItem extends Model
{
    protected $fillable = [
        'employee_salary_id', 'payroll_item_id', 'amount',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function salary(): BelongsTo
    {
        return $this->belongsTo(EmployeeSalary::class, 'employee_salary_id');
    }

    public function payrollItem(): BelongsTo
    {
        return $this->belongsTo(PayrollItem::class);
    }
}
