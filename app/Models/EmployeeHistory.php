<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * EmployeeHistory
 * ───────────────
 * A permanent snapshot of an employee's data taken on the day they exit.
 * Records here are never deleted — they are the system's audit trail.
 *
 * Created by: ActivateEmployees command when exit_date arrives.
 */
class EmployeeHistory extends Model
{
    protected $fillable = [
        'onboarding_id', 'employee_id', 'user_id',
        // Personal
        'full_name', 'official_document_id', 'date_of_birth', 'sex',
        'marital_status', 'religion', 'race', 'residential_address',
        'personal_contact_number', 'personal_email', 'bank_account_number',
        // Work
        'designation', 'department', 'company', 'office_location',
        'reporting_manager', 'company_email', 'start_date', 'exit_date',
        'employment_type', 'work_role',
        // Exit metadata
        'exit_reason', 'exit_remarks', 'archived_at',
    ];

    protected $casts = [
        'date_of_birth' => 'date',
        'start_date'    => 'date',
        'exit_date'     => 'date',
        'archived_at'   => 'date',
    ];

    // ── Relationships ─────────────────────────────────────────────────────
    public function onboarding() { return $this->belongsTo(Onboarding::class); }

    // ── Factory: build history snapshot from an Employee record ───────────
    public static function createFromEmployee(Employee $emp, string $reason = null, string $remarks = null): self
    {
        return self::create([
            'onboarding_id'           => $emp->onboarding_id,
            'employee_id'             => $emp->id,
            'user_id'                 => $emp->user_id,
            // Personal
            'full_name'               => $emp->full_name,
            'official_document_id'    => $emp->official_document_id,
            'date_of_birth'           => $emp->date_of_birth,
            'sex'                     => $emp->sex,
            'marital_status'          => $emp->marital_status,
            'religion'                => $emp->religion,
            'race'                    => $emp->race,
            'residential_address'     => $emp->residential_address,
            'personal_contact_number' => $emp->personal_contact_number,
            'personal_email'          => $emp->personal_email,
            'bank_account_number'     => $emp->bank_account_number,
            // Work
            'designation'             => $emp->designation,
            'department'              => $emp->department,
            'company'                 => $emp->company,
            'office_location'         => $emp->office_location,
            'reporting_manager'       => $emp->reporting_manager,
            'company_email'           => $emp->company_email,
            'start_date'              => $emp->start_date,
            'exit_date'               => $emp->exit_date,
            'employment_type'         => $emp->employment_type,
            'work_role'               => $emp->work_role,
            // Exit metadata
            'exit_reason'             => $reason,
            'exit_remarks'            => $remarks,
            'archived_at'             => now()->toDateString(),
        ]);
    }
}