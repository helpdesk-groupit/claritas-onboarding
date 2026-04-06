<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PayrollConfig extends Model
{
    protected $fillable = [
        'company',
        'epf_employee_rate', 'epf_employer_rate',
        'epf_employee_rate_senior', 'epf_employer_rate_senior',
        'socso_employee_rate', 'socso_employer_rate', 'socso_wage_ceiling',
        'eis_rate', 'eis_wage_ceiling',
        'hrdf_rate', 'hrdf_enabled',
        'default_working_days',
        'bank_name', 'bank_account_number',
        'lhdn_employer_no', 'epf_employer_no', 'socso_employer_no', 'eis_employer_no',
    ];

    protected $casts = [
        'epf_employee_rate' => 'decimal:2',
        'epf_employer_rate' => 'decimal:2',
        'epf_employee_rate_senior' => 'decimal:2',
        'epf_employer_rate_senior' => 'decimal:2',
        'socso_employee_rate' => 'decimal:4',
        'socso_employer_rate' => 'decimal:4',
        'socso_wage_ceiling' => 'decimal:2',
        'eis_rate' => 'decimal:4',
        'eis_wage_ceiling' => 'decimal:2',
        'hrdf_rate' => 'decimal:2',
        'hrdf_enabled' => 'boolean',
    ];

    public static function forCompany(?string $company = null): self
    {
        return static::where('company', $company)->first()
            ?? static::whereNull('company')->first()
            ?? new static();
    }
}
