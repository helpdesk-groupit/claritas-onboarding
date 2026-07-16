<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Employee extends Model
{
    use HasFactory;

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
        'employee_number',
        'designation', 'department', 'company', 'office_location',
        'reporting_manager', 'manager_id', 'reporting_manager_email',
        'company_email', 'start_date', 'exit_date', 'last_salary_date',
        'confirmation_date', 'employment_type', 'work_role', 'google_id',
        // AARF document
        'aarf_file_path',
        // Per-employee documents (uploaded by HR)
        'handbook_path', 'orientation_path',
        // Status
        'employment_status', 'resignation_reason', 'remarks',
        // Birthday e-card idempotency stamp (set by birthdays:send-wishes)
        'birthday_email_sent_year',
    ];

    protected $casts = [
        'active_from' => 'date',
        'active_until' => 'date',
        'date_of_birth' => 'date',
        'start_date' => 'date',
        'exit_date' => 'date',
        'last_salary_date' => 'date',
        'confirmation_date' => 'date',
        'is_disabled' => 'boolean',
        'consent_given_at' => 'datetime',
        'nric_file_paths' => 'array',
    ];

    /**
     * Always store the EXACT registered company name (Company Registration is the source of
     * truth). Any saved value — from a form, the onboarding flush, or a CSV import — is resolved
     * to its registered Company via Company::forName (tolerant of the Sdn Bhd / Sdn. Bhd. and
     * trailing-entity-word variations). An unregistered value is left as-is rather than dropped.
     */
    public function setCompanyAttribute($value): void
    {
        $this->attributes['company'] = $value
            ? (Company::forName($value)?->name ?? $value)
            : $value;
    }

    // ── Relationships ─────────────────────────────────────────────────────
    public function onboarding()
    {
        return $this->belongsTo(Onboarding::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function contracts()
    {
        return $this->hasMany(EmployeeContract::class)->latest();
    }

    public function aarf()
    {
        return $this->hasOne(\App\Models\Aarf::class, 'employee_id');
    }

    public function assetAssignments()
    {
        return $this->hasMany(\App\Models\AssetAssignment::class, 'employee_id');
    }

    public function offboarding()
    {
        return $this->hasOne(\App\Models\Offboarding::class, 'employee_id');
    }

    public function educationHistories()
    {
        return $this->hasMany(EmployeeEducationHistory::class)->orderBy('year_graduated', 'desc');
    }

    public function spouseDetails()
    {
        return $this->hasMany(EmployeeSpouseDetail::class);
    }

    public function spouseDetail()
    {
        return $this->hasOne(EmployeeSpouseDetail::class);
    } // kept for backwards compat

    public function emergencyContacts()
    {
        return $this->hasMany(EmployeeEmergencyContact::class)->orderBy('contact_order');
    }

    public function childRegistration()
    {
        return $this->hasOne(EmployeeChildRegistration::class);
    }

    public function editLogs()
    {
        return $this->hasMany(\App\Models\EmployeeEditLog::class)->latest();
    }

    // ── HRM module relationships ──────────────────────────────────────
    public function manager()
    {
        return $this->belongsTo(Employee::class, 'manager_id');
    }

    public function directReports()
    {
        return $this->hasMany(Employee::class, 'manager_id');
    }

    public function leaveApplications()
    {
        return $this->hasMany(\App\Models\LeaveApplication::class);
    }

    public function leaveBalances()
    {
        return $this->hasMany(\App\Models\LeaveBalance::class);
    }

    public function attendanceRecords()
    {
        return $this->hasMany(\App\Models\AttendanceRecord::class);
    }

    public function overtimeRequests()
    {
        return $this->hasMany(\App\Models\OvertimeRequest::class);
    }

    public function employeeSalary()
    {
        return $this->hasOne(\App\Models\EmployeeSalary::class)->where('is_active', true);
    }

    public function salaryHistory()
    {
        return $this->hasMany(\App\Models\EmployeeSalary::class)->orderByDesc('effective_from');
    }

    public function salaryAdjustments()
    {
        return $this->hasMany(\App\Models\SalaryAdjustment::class)->orderByDesc('effective_date');
    }

    public function expenseClaims()
    {
        return $this->hasMany(\App\Models\ExpenseClaim::class)->orderByDesc('year')->orderByDesc('month');
    }

    /** Company timeline — one row per stint, oldest first (open stint = current company). */
    public function companyHistories()
    {
        return $this->hasMany(EmployeeCompanyHistory::class)->orderBy('started_on');
    }

    /**
     * The company this employee was under on a given date, resolved from the company timeline.
     * Used to attribute historical employee-linked records (claims, leave, …) to the company
     * they were created under, rather than the employee's current company. Falls back to the
     * current company when no stint covers the date (or there is no timeline yet).
     */
    public function companyAsOf($date): ?string
    {
        if (! $date) {
            return $this->company;
        }
        $d = ($date instanceof \Carbon\Carbon ? $date->copy() : \Carbon\Carbon::parse($date))->toDateString();

        // Use the already-loaded relation when available (so callers can eager-load
        // employee.companyHistories and avoid an N+1); otherwise query the covering stint.
        $stints = $this->relationLoaded('companyHistories')
            ? $this->companyHistories
            : $this->companyHistories()->get();

        $stint = $stints
            ->filter(fn ($s) => $s->started_on?->toDateString() <= $d
                && ($s->ended_on === null || $s->ended_on->toDateString() >= $d))
            ->sort(function ($a, $b) {
                // Latest start wins.
                $c = strcmp((string) $b->started_on?->toDateString(), (string) $a->started_on?->toDateString());
                if ($c !== 0) {
                    return $c;
                }
                // Same start date (e.g. a same-day move left two overlapping stints):
                // prefer the still-open stint, then the most recently created row —
                // so attribution never resolves to a stale, already-closed stint.
                $ao = $a->ended_on === null ? 1 : 0;
                $bo = $b->ended_on === null ? 1 : 0;

                return $ao !== $bo ? $bo <=> $ao : $b->id <=> $a->id;
            })
            ->first();

        return $stint?->company ?? $this->company;
    }

    /**
     * Change this employee to $newCompany effective from $effectiveDate (which may be in the
     * past), recording it on the company timeline. Closes the current open stint at the effective
     * date and opens a new one from it. Returns a result array {status, message}:
     *   - 'changed'      — timeline split + company/office updated.
     *   - 'skipped_same' — already at that company (no-op).
     *   - 'skipped_date' — the effective date is on/before the current company's own start date
     *                      (would corrupt the timeline), so nothing was changed.
     */
    public function changeCompanyEffective(string $newCompany, ?string $newOffice, \Carbon\Carbon $effectiveDate, ?int $changedBy = null): array
    {
        $this->ensureInitialCompanyStint();

        if ($this->company === $newCompany) {
            return ['status' => 'skipped_same', 'message' => 'Already at '.$newCompany];
        }

        $open = $this->companyHistories()->whereNull('ended_on')->orderByDesc('started_on')->first();
        $effStr = $effectiveDate->toDateString();

        // The new stint can't start on/before the current company's own start — that would make
        // the current stint's window invalid (started_on > ended_on).
        if ($open && $open->started_on && $open->started_on->toDateString() >= $effStr) {
            return ['status' => 'skipped_date', 'message' => 'Effective date must be after their current company start ('.$open->started_on->format('d M Y').')'];
        }

        if ($open) {
            // The old company's last day is the day BEFORE the new one starts — nobody is at both
            // companies on the same day. The skip-guard above ensures this stays >= its start.
            $open->update(['ended_on' => $effectiveDate->copy()->subDay()->toDateString()]);
        }
        EmployeeCompanyHistory::create([
            'employee_id' => $this->id,
            'company' => $newCompany,
            'office_location' => $newOffice,
            'started_on' => $effStr,
            'ended_on' => null,
            'changed_by' => $changedBy,
        ]);

        $this->update(['company' => $newCompany, 'office_location' => $newOffice]);

        return ['status' => 'changed', 'message' => 'Changed to '.$newCompany.' effective '.$effectiveDate->format('d M Y')];
    }

    /**
     * Seed the opening stint for an employee that has none yet (e.g. hired after the timeline
     * was introduced, so they missed the backfill). Idempotent — no-op once a stint exists.
     * Starts from the employee's start date so the timeline reflects their real tenure.
     */
    public function ensureInitialCompanyStint(): void
    {
        if (! $this->company || $this->companyHistories()->exists()) {
            return;
        }

        EmployeeCompanyHistory::create([
            'employee_id' => $this->id,
            'company' => $this->company,
            'office_location' => $this->office_location,
            'started_on' => optional($this->start_date)->toDateString() ?? now()->toDateString(),
            'ended_on' => null,
            'changed_by' => null,
        ]);
    }

    /**
     * Reconcile the company timeline against this employee's CURRENT company/office_location
     * (call right after the employee record is updated). A company change closes the open stint
     * (ended_on = today) and opens a new one from today — returning to a previous company just
     * adds a fresh stint, the old ones are preserved. A same-company office move updates the
     * open stint's location in place. Only a superadmin can change company, so this only runs
     * for them. Returns true when a new stint was opened.
     */
    public function recordCompanyStintChange(?int $changedBy = null): bool
    {
        $company = $this->company;
        if (! $company) {
            return false;
        }

        $open = $this->companyHistories()->whereNull('ended_on')->orderByDesc('started_on')->first();

        // Same company — keep the stint, only refresh its office location if it moved.
        if ($open && $open->company === $company) {
            if ($open->office_location !== $this->office_location) {
                $open->update(['office_location' => $this->office_location]);
            }

            return false;
        }

        $today = now()->toDateString();
        if ($open) {
            // Old company's last day = the day before the new one starts (they can't be at both on
            // the same day). Clamp so a same-day change doesn't invert the stint.
            $end = now()->subDay()->toDateString();
            if ($open->started_on && $end < $open->started_on->toDateString()) {
                $end = $open->started_on->toDateString();
            }
            $open->update(['ended_on' => $end]);
        }

        // First-ever stint (no open row — e.g. a record predating the timeline) starts from the
        // employee's real start date; a genuine change starts from today.
        $startedOn = $open ? $today : (optional($this->start_date)->toDateString() ?? $today);

        EmployeeCompanyHistory::create([
            'employee_id' => $this->id,
            'company' => $company,
            'office_location' => $this->office_location,
            'started_on' => $startedOn,
            'ended_on' => null,
            'changed_by' => $changedBy,
        ]);

        return true;
    }

    /**
     * Preview (no mutation) what a company change to $newCompany effective
     * $effectiveDate would do, so the bulk page can warn before applying:
     *   - 'noop'    — already at that company.
     *   - 'append'  — effective date is after the current stint's start; a normal
     *                 forward move (no history removed).
     *   - 'rewrite' — effective date is on/before the current stint's start; applying
     *                 would REMOVE the stints in `removes` (those starting on/after the
     *                 effective date) and re-seat the company from that date. This is
     *                 the case the UI must confirm.
     *
     * @return array{mode:string, removes:\Illuminate\Support\Collection}
     */
    public function previewCompanyChange(string $newCompany, \Carbon\Carbon $effectiveDate): array
    {
        $this->ensureInitialCompanyStint();

        if ($this->company === $newCompany) {
            return ['mode' => 'noop', 'removes' => collect()];
        }

        $open = $this->companyHistories()->whereNull('ended_on')->orderByDesc('started_on')->first();
        $effStr = $effectiveDate->toDateString();

        if ($open && $open->started_on && $open->started_on->toDateString() >= $effStr) {
            $removes = $this->companyHistories()
                ->whereDate('started_on', '>=', $effStr)
                ->orderBy('started_on')->get();

            return ['mode' => 'rewrite', 'removes' => $removes];
        }

        return ['mode' => 'append', 'removes' => collect()];
    }

    /**
     * Apply a "rewrite recent history" company change: DELETE every stint that
     * starts on/after $effectiveDate, then re-seat $newCompany from that date. If
     * the stint now preceding the date is already $newCompany (the common "undo an
     * accidental move" case) it is simply reopened — merging cleanly with NO blip.
     * Only call after the superadmin has confirmed (see previewCompanyChange).
     *
     * @return array{status:string, removed:array<string>, message:string}
     */
    public function rewriteCompanyFrom(string $newCompany, ?string $newOffice, \Carbon\Carbon $effectiveDate, ?int $changedBy = null): array
    {
        $this->ensureInitialCompanyStint();
        $effStr = $effectiveDate->toDateString();

        $removed = $this->companyHistories()->whereDate('started_on', '>=', $effStr)->orderBy('started_on')->get();
        $removedLabels = $removed->map(fn ($s) => self::stintLabel($s))->all();
        $removedCompanies = $removed->pluck('company')->unique()->values()->all();

        // A concise note explaining the overwrite, shown on the profile timeline.
        $note = ! empty($removedCompanies)
            ? 'Overwrote earlier record — was under '.implode(', ', $removedCompanies).', changed to '
                .$newCompany.' effective '.$effectiveDate->format('d M Y')
                .' (back-dated before the previous start, so that period was removed).'
            : null;

        foreach ($removed as $stint) {
            $stint->delete();
        }

        $prev = $this->companyHistories()->orderByDesc('started_on')->first();

        if ($prev && $prev->company === $newCompany) {
            // Reverting to the immediately-previous company → reopen it (merge, no blip).
            $prev->update(['ended_on' => null, 'note' => $note]);
            $this->update(['company' => $prev->company, 'office_location' => $prev->office_location]);
        } else {
            if ($prev) {
                $prev->update(['ended_on' => $effectiveDate->copy()->subDay()->toDateString()]);
            }
            EmployeeCompanyHistory::create([
                'employee_id' => $this->id,
                'company' => $newCompany,
                'office_location' => $newOffice,
                'started_on' => $effStr,
                'ended_on' => null,
                'changed_by' => $changedBy,
                'note' => $note,
            ]);
            $this->update(['company' => $newCompany, 'office_location' => $newOffice]);
        }

        return [
            'status' => 'rewritten',
            'removed' => $removedLabels,
            'message' => 'Set '.$this->company.' effective '.$effectiveDate->format('d M Y').' — removed '.count($removedLabels).' timeline '.(count($removedLabels) === 1 ? 'entry' : 'entries').'.',
        ];
    }

    /** Human-readable one-line label for a stint (used in change previews/messages). */
    public static function stintLabel(EmployeeCompanyHistory $s): string
    {
        return $s->company.' ('.optional($s->started_on)->format('d M Y').' → '.($s->ended_on ? $s->ended_on->format('d M Y') : 'Present').')';
    }

    /**
     * Resolve manager_id from a reporting manager's name string.
     *
     * The `reporting_manager` column is free-text and historically holds names
     * in several shapes — exact full_name, "PreferredName FullName" (the most
     * common, e.g. "Petrina Goh Shze Yinn" for full_name "Goh Shze Yinn"
     * preferred "Petrina"), or a fragment. This resolver tries, in order:
     *   1. exact full_name match
     *   2. exact "preferred_name + full_name" / "full_name + preferred_name"
     *   3. the string is a substring of a full_name (or vice versa)
     *
     * STRICT: returns the match only when exactly ONE active employee
     * resolves. If zero or several match (typo, genuine duplicate records),
     * returns null — the caller must not guess. Identity must be certain
     * because manager_id drives ticket routing and approval chains.
     *
     * @return int|null The unique matching active employee id, or null.
     */
    public static function resolveManagerId(?string $managerName): ?int
    {
        if (! $managerName) {
            return null;
        }
        $target = self::normaliseName($managerName);
        if ($target === '') {
            return null;
        }

        $candidates = [];
        foreach (static::whereNull('active_until')->get(['id', 'full_name', 'preferred_name']) as $e) {
            $full = self::normaliseName($e->full_name);
            $pref = self::normaliseName($e->preferred_name);
            if ($full === '') {
                continue;
            }

            $match = false;
            if ($full === $target) {
                $match = true;                                   // exact full_name
            } elseif ($pref !== '' && trim($pref.' '.$full) === $target) {
                $match = true;                                   // preferred + full
            } elseif ($pref !== '' && trim($full.' '.$pref) === $target) {
                $match = true;                                   // full + preferred
            } elseif (str_contains($target, $full) || str_contains($full, $target)) {
                $match = true;                                   // substring either way
            }

            if ($match) {
                $candidates[$e->id] = true;
            }
        }

        // Exactly one unambiguous match, else null — never guess.
        return count($candidates) === 1 ? (int) array_key_first($candidates) : null;
    }

    /** Lowercase, strip punctuation, collapse whitespace — for name comparison. */
    public static function normaliseName(?string $name): string
    {
        if ($name === null) {
            return '';
        }
        $clean = str_replace(['.', ',', '/'], ' ', $name);
        $clean = preg_replace('/\s+/', ' ', $clean);

        return mb_strtolower(trim($clean));
    }

    // Resolve AARF regardless of whether it's linked via onboarding_id or employee_id
    public function resolveAarf(): ?\App\Models\Aarf
    {
        if ($this->onboarding_id) {
            $aarf = \App\Models\Aarf::where('onboarding_id', $this->onboarding_id)->first();
            if ($aarf) {
                return $aarf;
            }
        }

        return \App\Models\Aarf::where('employee_id', $this->id)->first();
    }

    // ── Helper: populate employee record from onboarding data ─────────────
    // Called by the activation job/command when start_date arrives
    public function populateFromOnboarding(): void
    {
        $ob = $this->onboarding?->load(['personalDetail', 'workDetail']);
        if (! $ob) {
            return;
        }

        $p = $ob->personalDetail;
        $w = $ob->workDetail;

        $this->update([
            // Personal
            'full_name' => $p?->full_name,
            'preferred_name' => $p?->preferred_name,
            'official_document_id' => $p?->official_document_id,
            'date_of_birth' => $p?->date_of_birth,
            'sex' => $p?->sex,
            'marital_status' => $p?->marital_status,
            'religion' => $p?->religion,
            'race' => $p?->race,
            'is_disabled' => $p?->is_disabled ?? false,
            'residential_address' => $p?->residential_address,
            'personal_contact_number' => $p?->personal_contact_number,
            'house_tel_no' => $p?->house_tel_no,
            'personal_email' => $p?->personal_email,
            'bank_account_number' => $p?->bank_account_number,
            'bank_name' => $p?->bank_name,
            'epf_no' => $p?->epf_no,
            'income_tax_no' => $p?->income_tax_no,
            'socso_no' => $p?->socso_no,
            'nric_file_path' => $p?->nric_file_path,
            'nric_file_paths' => $p?->nric_file_paths,
            'consent_given_at' => $p?->consent_given_at,
            'consent_ip' => $p?->consent_ip,
            // Work
            'employee_number' => $w?->employee_number,
            'designation' => $w?->designation,
            'department' => $w?->department,
            'company' => $w?->company,
            'office_location' => $w?->office_location,
            'reporting_manager' => $w?->reporting_manager,
            'company_email' => $w?->company_email,
            'start_date' => $w?->start_date,
            'exit_date' => $w?->exit_date,
            'last_salary_date' => $w?->last_salary_date,
            'confirmation_date' => $w?->confirmation_date,
            'employment_type' => $w?->employment_type,
            'work_role' => $w?->role,
            'google_id' => $w?->google_id,
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
