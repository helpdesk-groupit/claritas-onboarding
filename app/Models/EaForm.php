<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EaForm extends Model
{
    protected $fillable = [
        'employee_id', 'year',
        'employer_name', 'employer_address', 'employer_tax_no',
        'employee_name', 'employee_tax_no', 'employee_ic_no',
        'employee_epf_no', 'employee_socso_no',
        'designation', 'employment_start_date', 'employment_end_date',
        'gross_salary', 'overtime_pay', 'commission', 'allowances',
        'gross_remuneration', 'benefits_in_kind', 'value_of_living_accommodation',
        'pension_or_annuity', 'gratuity', 'total_remuneration',
        'epf_employee', 'socso_employee', 'eis_employee',
        'pcb_paid', 'zakat', 'total_deductions',
        'epf_employer', 'socso_employer', 'eis_employer', 'hrdf_employer',
        'status', 'generated_by', 'finalized_at', 'notes',
    ];

    protected $casts = [
        'employment_start_date' => 'date',
        'employment_end_date'   => 'date',
        'finalized_at'          => 'datetime',
        'gross_salary'          => 'decimal:2',
        'overtime_pay'          => 'decimal:2',
        'commission'            => 'decimal:2',
        'allowances'            => 'decimal:2',
        'gross_remuneration'    => 'decimal:2',
        'benefits_in_kind'      => 'decimal:2',
        'value_of_living_accommodation' => 'decimal:2',
        'pension_or_annuity'    => 'decimal:2',
        'gratuity'              => 'decimal:2',
        'total_remuneration'    => 'decimal:2',
        'epf_employee'          => 'decimal:2',
        'socso_employee'        => 'decimal:2',
        'eis_employee'          => 'decimal:2',
        'pcb_paid'              => 'decimal:2',
        'zakat'                 => 'decimal:2',
        'total_deductions'      => 'decimal:2',
        'epf_employer'          => 'decimal:2',
        'socso_employer'        => 'decimal:2',
        'eis_employer'          => 'decimal:2',
        'hrdf_employer'         => 'decimal:2',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function generator()
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    public function getNetPayAttribute(): float
    {
        return (float) $this->total_remuneration - (float) $this->total_deductions;
    }

    public function statusBadge(): string
    {
        return match ($this->status) {
            'draft'     => '<span class="badge bg-secondary">Draft</span>',
            'finalized' => '<span class="badge bg-success">Finalized</span>',
            default     => '<span class="badge bg-light text-dark">' . ucfirst($this->status) . '</span>',
        };
    }
}
