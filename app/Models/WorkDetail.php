<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkDetail extends Model
{
    protected $fillable = [
        'onboarding_id', 'employee_status', 'staff_status', 'employment_type',
        'designation', 'company', 'office_location', 'reporting_manager',
        'reporting_manager_email', 'start_date', 'exit_date',
        'company_email', 'google_id', 'department', 'role',
    ];

    protected $casts = [
        'start_date' => 'date',
        'exit_date'  => 'date',
    ];

    public function onboarding() { return $this->belongsTo(Onboarding::class); }
}
