<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Employee extends Model
{
    protected $fillable = [
        'onboarding_id', 'user_id', 'active_from', 'active_until',
        // Personal profile (official record, editable by user)
        'full_name', 'preferred_name', 'official_document_id', 'date_of_birth', 'sex',
        'marital_status', 'religion', 'race', 'is_disabled', 'residential_address',
        'personal_contact_number', 'house_tel_no', 'personal_email',
        'bank_account_number', 'bank_name',
        'epf_no', 'income_tax_no', 'socso_no',
        'epf_category', 'is_resident', 'nationality',
        'nric_file_path',
        'nric_file_paths',
        'consent_given_at', 'consent_ip',
        // Work info (official record)
        'designation', 'department', 'company', 'office_location',
        'reporting_manager', 'manager_id', 'reporting_manager_email',
        'company_email', 'start_date', 'exit_date', 'last_salary_date',
        'employment_type', 'work_role', 'google_id',
        // AARF document
        'aarf_file_path',
        // Per-employee documents (uploaded by HR)
        'handbook_path', 'orientation_path',
        // Status
        'employment_status', 'resignation_reason', 'remarks',
    ];

    protected $casts = [
        'active_from'      => 'date',
        'active_until'     => 'date',
        'date_of_birth'    => 'date',
        'start_date'       => 'date',
        'exit_date'        => 'date',
        'last_salary_date' => 'date',
        'is_disabled'      => 'boolean',
        'consent_given_at' => 'datetime',
        'nric_file_paths'  => 'array',
    ];

    // ── Relationships ─────────────────────────────────────────────────────
    public function onboarding() { return $this->belongsTo(Onboarding::class); }
    public function user()       { return $this->belongsTo(User::class); }
    public function contracts()  { return $this->hasMany(EmployeeContract::class)->latest(); }
    public function aarf()             { return $this->hasOne(\App\Models\Aarf::class, 'employee_id'); }
    public function assetAssignments() { return $this->hasMany(\App\Models\AssetAssignment::class, 'employee_id'); }
    public function offboarding() { return $this->hasOne(\App\Models\Offboarding::class, 'employee_id'); }
    public function educationHistories() { return $this->hasMany(EmployeeEducationHistory::class)->orderBy('year_graduated', 'desc'); }
    public function spouseDetails()      { return $this->hasMany(EmployeeSpouseDetail::class); }
    public function spouseDetail()       { return $this->hasOne(EmployeeSpouseDetail::class); } // kept for backwards compat
    public function emergencyContacts()  { return $this->hasMany(EmployeeEmergencyContact::class)->orderBy('contact_order'); }
    public function childRegistration()  { return $this->hasOne(EmployeeChildRegistration::class); }
    public function editLogs()           { return $this->hasMany(\App\Models\EmployeeEditLog::class)->latest(); }

    // ── HRM module relationships ──────────────────────────────────────
    public function manager()            { return $this->belongsTo(Employee::class, 'manager_id'); }
    public function directReports()      { return $this->hasMany(Employee::class, 'manager_id'); }
    public function leaveApplications()  { return $this->hasMany(\App\Models\LeaveApplication::class); }
    public function leaveBalances()      { return $this->hasMany(\App\Models\LeaveBalance::class); }
    public function attendanceRecords()  { return $this->hasMany(\App\Models\AttendanceRecord::class); }
    public function overtimeRequests()   { return $this->hasMany(\App\Models\OvertimeRequest::class); }
    public function employeeSalary()     { return $this->hasOne(\App\Models\EmployeeSalary::class)->where('is_active', true); }
    public function salaryHistory()      { return $this->hasMany(\App\Models\EmployeeSalary::class)->orderByDesc('effective_from'); }
    public function salaryAdjustments()  { return $this->hasMany(\App\Models\SalaryAdjustment::class)->orderByDesc('effective_date'); }
    public function expenseClaims()      { return $this->hasMany(\App\Models\ExpenseClaim::class)->orderByDesc('year')->orderByDesc('month'); }

    /**
     * Resolve manager_id from a reporting manager's full name.
     * Returns the Employee ID of the first active employee matching the name.
     */
    public static function resolveManagerId(?string $managerName): ?int
    {
        if (!$managerName) return null;
        return static::where('full_name', $managerName)
            ->whereNull('active_until')
            ->value('id');
    }

    // Resolve AARF regardless of whether it's linked via onboarding_id or employee_id
    public function resolveAarf(): ?\App\Models\Aarf
    {
        if ($this->onboarding_id) {
            $aarf = \App\Models\Aarf::where('onboarding_id', $this->onboarding_id)->first();
            if ($aarf) return $aarf;
        }
        return \App\Models\Aarf::where('employee_id', $this->id)->first();
    }

    // ── Helper: populate employee record from onboarding data ─────────────
    // Called by the activation job/command when start_date arrives
    public function populateFromOnboarding(): void
    {
        $ob = $this->onboarding?->load(['personalDetail', 'workDetail']);
        if (!$ob) return;

        $p = $ob->personalDetail;
        $w = $ob->workDetail;

        $this->update([
            // Personal
            'full_name'               => $p?->full_name,
            'preferred_name'          => $p?->preferred_name,
            'official_document_id'    => $p?->official_document_id,
            'date_of_birth'           => $p?->date_of_birth,
            'sex'                     => $p?->sex,
            'marital_status'          => $p?->marital_status,
            'religion'                => $p?->religion,
            'race'                    => $p?->race,
            'is_disabled'             => $p?->is_disabled ?? false,
            'residential_address'     => $p?->residential_address,
            'personal_contact_number' => $p?->personal_contact_number,
            'house_tel_no'            => $p?->house_tel_no,
            'personal_email'          => $p?->personal_email,
            'bank_account_number'     => $p?->bank_account_number,
            'bank_name'               => $p?->bank_name,
            'epf_no'                  => $p?->epf_no,
            'income_tax_no'           => $p?->income_tax_no,
            'socso_no'                => $p?->socso_no,
            'nric_file_path'          => $p?->nric_file_path,
            'nric_file_paths'         => $p?->nric_file_paths,
            'consent_given_at'        => $p?->consent_given_at,
            'consent_ip'              => $p?->consent_ip,
            // Work
            'designation'      => $w?->designation,
            'department'       => $w?->department,
            'company'          => $w?->company,
            'office_location'  => $w?->office_location,
            'reporting_manager'=> $w?->reporting_manager,
            'company_email'    => $w?->company_email,
            'start_date'       => $w?->start_date,
            'exit_date'        => $w?->exit_date,
            'employment_type'  => $w?->employment_type,
            'work_role'        => $w?->role,
            'google_id'        => $w?->google_id,
        ]);

        // Resolve manager_id from reporting_manager name
        if ($w?->reporting_manager) {
            $managerId = static::resolveManagerId($w->reporting_manager);
            if ($managerId && $managerId !== $this->id) {
                $this->update([
                    'manager_id' => $managerId,
                    'reporting_manager_email' => $w->reporting_manager_email,
                ]);
            }
        }

        // Flush invite staging data (education, spouse, emergency, children) into relationship tables
        \App\Http\Controllers\OnboardingInviteController::flushStagingToEmployee(
            $this,
            $p?->invite_staging_json
        );
    }
}