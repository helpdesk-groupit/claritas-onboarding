<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Offboarding extends Model
{
    protected $fillable = [
        'onboarding_id', 'employee_id',
        'full_name', 'company', 'department', 'designation',
        'company_email', 'reporting_manager_email', 'personal_email',
        'exit_date', 'reason', 'remarks',
        'calendar_reminder_status', 'exiting_email_status', 'aarf_status',
        'notice_email_status', 'reminder_email_status', 'week_reminder_email_status', 'sendoff_email_status',
        'asset_cleaning_status', 'deactivation_status',
        'assigned_pic_user_id',
    ];

    protected $casts = ['exit_date' => 'date'];

    public function onboarding() { return $this->belongsTo(Onboarding::class); }
    public function employee()   { return $this->belongsTo(Employee::class); }
    public function picUser()    { return $this->belongsTo(\App\Models\User::class, 'assigned_pic_user_id'); }

    /**
     * Create or update offboarding record from an Employee.
     */
    public static function createFromEmployee(Employee $emp): self
    {
        $matchKey = $emp->onboarding_id
            ? ['onboarding_id' => $emp->onboarding_id]
            : ['employee_id'   => $emp->id];

        return self::firstOrCreate(
            $matchKey,
            [
                'onboarding_id'  => $emp->onboarding_id,
                'employee_id'    => $emp->id,
                'full_name'      => $emp->full_name,
                'company'        => $emp->company,
                'department'     => $emp->department,
                'designation'    => $emp->designation,
                'company_email'  => $emp->company_email,
                'personal_email' => $emp->personal_email,
                'exit_date'      => $emp->exit_date,
            ]
        );
    }
}