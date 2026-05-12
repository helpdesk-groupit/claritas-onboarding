<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class Ticket extends Model
{
    use HasFactory;

    protected $fillable = [
        'ticket_number', 'user_id', 'company_id', 'assigned_to', 'assigned_at',
        'department', 'priority', 'status', 'subject', 'description',
        'resolved_at', 'last_reminder_sent_at',
    ];

    protected $casts = [
        'assigned_at'           => 'datetime',
        'resolved_at'           => 'datetime',
        'last_reminder_sent_at' => 'datetime',
    ];

    public const DEPARTMENTS = [
        // Core (pinned at top of dropdowns)
        'HRA', 'Group IT', 'Finance', 'Admin',
        // Extended (alphabetical)
        'Community', 'Consulting', 'Content', 'Design', 'Digital', 'Ecommerce',
        'KOL', 'Management', 'Marketing', 'Media', 'Production', 'Projects', 'Sales', 'Tech',
    ];
    public const PRIORITIES  = ['Low', 'Medium', 'High', 'Urgent'];

    /**
     * Status lifecycle:
     *   Open         → ticket raised, no PIC assigned
     *   In Progress  → PIC assigned (set automatically on assignment)
     *   Pending      → no PIC for 24h+ (set automatically by hourly cron)
     *   Resolved     → terminal: PIC marked it solved (Auto-Archived = Yes; in Archived tab)
     *   Closed       → terminal: manually closed without resolution (Auto-Archived = No; in Archived tab)
     */
    public const STATUSES = ['Open', 'In Progress', 'Pending', 'Resolved', 'Closed'];

    /** Statuses considered terminal — these tickets live in the "Archived" tab. */
    public const ARCHIVED_STATUSES = ['Resolved', 'Closed'];

    /** Statuses considered active — these tickets show in the All / Assigned tabs. */
    public const ACTIVE_STATUSES = ['Open', 'In Progress', 'Pending'];

    /**
     * Resolution-time thresholds (minutes) used to colour the Department
     * Health card. Avg ≤ GOOD = green, ≤ AMBER = amber, > AMBER = red.
     * Tweak these to retune the dashboard performance bands.
     */
    public const HEALTH_GOOD_MAX_MINUTES  = 1440;   // 24 hours
    public const HEALTH_AMBER_MAX_MINUTES = 4320;   // 72 hours

    /**
     * App-role gated departments: PIC eligibility = users.role ∈ list.
     * Used for HR/IT/Finance/Admin where users carry app-level permissions
     * beyond just being a department manager.
     *
     * For departments listed in WORK_ROLE_MANAGER_DEPARTMENTS, eligibility is
     * determined by Employee.work_role + Employee.department instead.
     */
    public const DEPARTMENT_MANAGER_ROLES = [
        'HRA'      => ['hr_manager', 'hr_executive', 'superadmin', 'system_admin'],
        'Group IT' => ['it_manager', 'it_executive', 'superadmin', 'system_admin'],
        'Finance'  => ['finance_manager', 'finance_executive', 'superadmin', 'system_admin'],
        'Admin'    => ['superadmin', 'system_admin'],
    ];

    /**
     * Departments whose PIC pool is determined by Employee.work_role = 'manager'
     * AND Employee.department = <this dept>, instead of by users.role.
     *
     * Superadmin/system_admin remain eligible as a catch-all for these departments
     * (handled in eligibleManagersQuery).
     */
    public const WORK_ROLE_MANAGER_DEPARTMENTS = [
        'Community', 'Consulting', 'Content', 'Design', 'Digital', 'Ecommerce',
        'KOL', 'Management', 'Marketing', 'Media', 'Production', 'Projects', 'Sales', 'Tech',
    ];

    /**
     * Allowed ticket subjects per department — drives the controlled-vocabulary
     * dropdown on the Raise New Ticket form. Selecting one of these standardised
     * subjects means analytics aggregate cleanly (no more "Incorrect details" vs
     * "Incorrect information" duplicates).
     *
     * Edit this list freely — no schema or migration needed. Existing tickets with
     * arbitrary subjects keep their text untouched.
     */
    public const DEPARTMENT_SUBJECTS = [
        // Core
        'HRA' => [
            'Incorrect Personal Details',
            'Leave Request Issue',
            'Payroll / Salary Query',
            'Benefits Enquiry',
            'Onboarding Issue',
            'Offboarding / Resignation',
            'Employment Letter Request',
            'Other',
        ],
        'Group IT' => [
            'Email Problem',
            'Laptop / Hardware Issue',
            'Software Installation / Access',
            'Network / Internet Issue',
            'Account Lockout',
            'Password Reset',
            'Printer Issue',
            'Other',
        ],
        'Finance' => [
            'Expense Reimbursement',
            'Invoice Query',
            'Vendor Payment',
            'Tax / Compliance Query',
            'Budget Request',
            'Other',
        ],
        'Admin' => [
            'Office Supplies',
            'Facility / Maintenance',
            'Travel Booking',
            'Meeting Room Booking',
            'General Enquiry',
            'Other',
        ],
        // Extended
        'Community' => [
            'Member Enquiry',
            'Event Coordination',
            'Communications Request',
            'Other',
        ],
        'Consulting' => [
            'Client Engagement',
            'Resource Allocation',
            'Project Scoping',
            'Other',
        ],
        'Content' => [
            'Content Request',
            'Editorial Review',
            'Publishing Issue',
            'Other',
        ],
        'Design' => [
            'Design Request',
            'Brand Asset Request',
            'Approval Required',
            'Other',
        ],
        'Digital' => [
            'Website Issue',
            'SEO / Analytics Query',
            'Digital Tool Access',
            'Other',
        ],
        'Ecommerce' => [
            'Order Issue',
            'Payment Issue',
            'Inventory Query',
            'Customer Complaint',
            'Other',
        ],
        'KOL' => [
            'Influencer Engagement',
            'Content Collaboration',
            'Campaign Brief',
            'Contract / Agreement',
            'Payment / Compensation',
            'Other',
        ],
        'Management' => [
            'Approval Request',
            'Policy Query',
            'Strategic Discussion',
            'Other',
        ],
        'Marketing' => [
            'Campaign Request',
            'Content Approval',
            'Brand Asset Request',
            'Analytics Query',
            'Other',
        ],
        'Media' => [
            'Media Request',
            'Press Enquiry',
            'Asset Distribution',
            'Other',
        ],
        'Production' => [
            'Equipment Issue',
            'Schedule Change',
            'Quality Issue',
            'Material Request',
            'Other',
        ],
        'Projects' => [
            'Project Status Update',
            'Resource Request',
            'Timeline Change',
            'Risk / Issue Report',
            'Other',
        ],
        'Sales' => [
            'Lead Query',
            'Pricing Approval',
            'Contract Review',
            'Commission Issue',
            'Other',
        ],
        'Tech' => [
            'Bug Report',
            'Feature Request',
            'Code Review Request',
            'Deployment Issue',
            'Performance Issue',
            'Other',
        ],
    ];

    /**
     * Roles that are eligible to be assigned as PIC but are NOT considered
     * department managers.
     *
     * Interns can:
     *   - Be assigned a ticket (PIC pool includes them)
     *   - Chat in tickets they're assigned to
     *   - Update the status of tickets they're assigned to
     *
     * Interns CANNOT:
     *   - Assign or reassign PIC (only managers can)
     *   - See all tickets in their department (visibleTo limits them to created/assigned)
     *   - Receive "new ticket raised" notifications (those go to managers only)
     */
    public const DEPARTMENT_PIC_EXTRA_ROLES = [
        'HRA'      => ['hr_intern'],
        'Group IT' => ['it_intern'],
    ];

    /**
     * NOTE: The previous DEPARTMENT_SCOPES constant has been retired.
     * Department-to-company access is now configured at runtime via the
     * `department_company_access` pivot table, editable by superadmins on the
     * Department Settings page (/superadmin/department-settings).
     *
     * Empty assignments for a department = "serves all companies" (backward
     * compat default). One or more rows = "serves only those companies".
     */

    // ── Relationships ─────────────────────────────────────────────────────
    public function creator()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function messages()
    {
        return $this->hasMany(TicketMessage::class)->orderBy('id');
    }

    public function attachments()
    {
        return $this->hasMany(TicketAttachment::class)->orderBy('id');
    }

    public function editLogs()
    {
        return $this->hasMany(TicketEditLog::class)->latest();
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    // ── Scopes ────────────────────────────────────────────────────────────

    /**
     * Restrict tickets to those visible on the Ticket Management page.
     *
     * - Superadmin / system_admin → everything (system-wide admins)
     * - Managers (incl. executives) → tickets in their managed department(s)
     *   whose company is in that dept's served-companies cluster (auto-derived
     *   members ∪ pivot extras). So a Tech manager at Claritas — when Tech is
     *   configured to serve Claritas + Enlinea + Nuren — sees Tech tickets
     *   from all three. The dept's coverage configuration drives the cluster;
     *   the manager's own company doesn't restrict it.
     * - Non-managers (interns assigned to tickets) → only tickets assigned to
     *   them as PIC. Visibility piggy-backs on assignment; if a manager assigned
     *   the intern to a ticket from any served company, the intern sees it.
     *
     * Note: a user's own RAISED tickets are NOT included here — those belong
     * on the Self-Service page (/tickets), which has its own filter.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isSuperadmin() || $user->isSystemAdmin()) {
            return $query;
        }

        $managedDepartments = self::departmentsManagedBy($user);

        if (empty($managedDepartments)) {
            // Non-manager (intern with assigned tickets, etc.): only see
            // tickets assigned to them. The assignment itself is the gate —
            // whoever assigned the intern accepted the cross-company exposure.
            return $query->where('assigned_to', $user->id);
        }

        // Manager: tickets in any managed dept whose company is in that dept's
        // served-companies cluster. Built as per-dept OR clauses so a manager
        // of multiple depts gets each dept's own cluster, not the union.
        return $query->where(function ($outer) use ($managedDepartments) {
            foreach ($managedDepartments as $dept) {
                $servedIds = self::companiesServingDepartment($dept);
                $outer->orWhere(function ($inner) use ($dept, $servedIds) {
                    $inner->where('tickets.department', $dept);
                    if (!empty($servedIds)) {
                        $inner->whereIn('tickets.company_id', $servedIds);
                    }
                    // Empty servedIds = no auto-derived AND no pivot extras =
                    // dept serves all companies (graceful default) → no filter.
                });
            }
        });
    }

    /**
     * Constrains a query to tickets whose department serves the given user's
     * company — accounting for both auto-derived defaults (member companies)
     * and explicit pivot extras.
     *
     * Used by the non-manager branch of visibleTo() so that interns / employees
     * with assigned tickets can't see across the dept-company boundary either.
     */
    private static function filterToDeptsServingUserCompany($query, ?string $userCompany)
    {
        if (empty($userCompany)) {
            $query->whereRaw('1 = 0');
            return;
        }
        $companyId = self::resolveCompanyId($userCompany);
        if (!$companyId) {
            $query->whereRaw('1 = 0');
            return;
        }

        // Pre-compute which depts serve the user's company. Empty served-list =
        // unconfigured dept (no members AND no extras) = serves all (graceful).
        $allowedDepts = [];
        foreach (self::DEPARTMENTS as $dept) {
            $servedIds = self::companiesServingDepartment($dept);
            if (empty($servedIds) || in_array($companyId, $servedIds, true)) {
                $allowedDepts[] = $dept;
            }
        }

        if (empty($allowedDepts)) {
            $query->whereRaw('1 = 0');
            return;
        }
        $query->whereIn('tickets.department', $allowedDepts);
    }

    /**
     * Adds OR-clauses to a query for "tickets in $managedDepartments where the
     * dept actually serves the creator's company". Departments serving all
     * companies (no rows in pivot) match every ticket in that dept.
     *
     * Uses an explicit whereExists subquery on `employees` to bypass any
     * potential Eloquent dotted-whereHas quirks across versions.
     */
    public static function orWhereInManagedDeptsServingCreator($query, array $managedDepartments): void
    {
        if (empty($managedDepartments)) return;

        foreach ($managedDepartments as $dept) {
            // Combined served list: auto-derived from members + extras from pivot.
            $servedCompanies = self::companyNamesServingDepartment($dept);

            if (empty($servedCompanies)) {
                // Unconfigured dept (no members AND no extras) → serves all
                $query->orWhere('tickets.department', $dept);
                continue;
            }

            $query->orWhere(function ($q) use ($dept, $servedCompanies) {
                $q->where('tickets.department', $dept)
                  ->whereExists(function ($sub) use ($servedCompanies) {
                      $sub->select(\DB::raw(1))
                          ->from('employees')
                          ->whereColumn('employees.user_id', 'tickets.user_id')
                          ->whereIn('employees.company', $servedCompanies);
                  });
            });
        }
    }

    public function scopeForDepartment(Builder $query, string $department): Builder
    {
        return $query->where('department', $department);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /**
     * Return the list of departments the given user can manage tickets for.
     * Honours both app-role gating (HRA, Group IT, Finance, Admin) and
     * work-role gating (the 13 departments using Employee.work_role + Employee.department).
     */
    public static function departmentsManagedBy(User $user): array
    {
        $managed = [];
        foreach (self::DEPARTMENTS as $dept) {
            if (self::isManagerOf($user, $dept)) {
                $managed[] = $dept;
            }
        }
        return $managed;
    }

    /**
     * Returns true if $user is considered a manager of $department.
     *
     * Rules:
     * - For app-role-gated departments: user.role ∈ DEPARTMENT_MANAGER_ROLES[$department]
     * - For work-role-gated departments: employee.work_role = 'manager' AND
     *                                    employee.department = $department
     * - PLUS the department must serve the user's company (per the pivot table).
     *   If the dept has no rows in department_company_access → serves all companies
     *   → company check is skipped.
     *
     * Note: superadmin/system_admin are NOT auto-included here; callers that want
     * "can manage everything" must combine this with isSuperadmin()/isSystemAdmin()
     * checks. Keeps this helper strictly about department managership.
     */
    public static function isManagerOf(User $user, string $department): bool
    {
        // Step 1: role / work-role check
        if (in_array($department, self::WORK_ROLE_MANAGER_DEPARTMENTS, true)) {
            $emp = $user->employee;
            if (!$emp || $emp->work_role !== 'manager' || $emp->department !== $department) {
                return false;
            }
            $userCompany = $emp->company;
        } else {
            $deptRoles = self::DEPARTMENT_MANAGER_ROLES[$department] ?? [];
            if (!in_array($user->role, $deptRoles, true)) {
                return false;
            }
            $userCompany = $user->employee?->company;
        }

        // Step 2: company-served check (skipped if dept serves all companies)
        $servedCompanyIds = self::companiesServingDepartment($department);
        if (empty($servedCompanyIds)) {
            return true;
        }
        if (empty($userCompany)) {
            return false; // dept restricted but user has no company
        }
        $userCompanyId = self::resolveCompanyId($userCompany);
        return $userCompanyId && in_array($userCompanyId, $servedCompanyIds, true);
    }

    // ── Department × Company access helpers (uses pivot table) ────────────

    /**
     * Returns the IDs of companies served by the given department.
     *
     * Combined from:
     *   - Auto-default: companies where any member of this dept works
     *     (e.g. Group IT auto-serves any company where an it_manager / it_executive /
     *     it_intern is registered).
     *   - Extras: companies explicitly assigned via the Department Settings UI
     *     (department_company_access pivot).
     *
     * Empty result = "no members AND no extras" — for unconfigured departments
     * (e.g. Admin, or work-role-gated depts with no employees yet) we fall back
     * to "serves all companies" so they don't accidentally lock out.
     */
    public static function companiesServingDepartment(string $department): array
    {
        $autoIds = self::defaultServedCompanyIdsForDepartment($department);
        $extraIds = DepartmentCompanyAccess::where('department', $department)
            ->pluck('company_id')
            ->map(fn($id) => (int) $id)
            ->toArray();

        return array_values(array_unique(array_merge($autoIds, $extraIds)));
    }

    /**
     * Companies where any member of $department works — derived from
     * users.role (for app-role-gated depts) or employees.department
     * (for work-role-gated depts). Excludes superadmin/system_admin since
     * they aren't department-specific members.
     */
    private static function defaultServedCompanyIdsForDepartment(string $department): array
    {
        if (in_array($department, self::WORK_ROLE_MANAGER_DEPARTMENTS, true)) {
            // Work-role-gated: members = anyone with employee.department = $department
            $companyNames = Employee::where('department', $department)
                ->whereNotNull('company')
                ->where('company', '!=', '')
                ->pluck('company')
                ->unique()
                ->values()
                ->toArray();
        } else {
            // App-role-gated: members = users with role in (manager + extras),
            // excluding system-wide admins
            $managerRoles = self::DEPARTMENT_MANAGER_ROLES[$department] ?? [];
            $extraRoles   = self::DEPARTMENT_PIC_EXTRA_ROLES[$department] ?? [];
            $memberRoles  = array_values(array_diff(
                array_merge($managerRoles, $extraRoles),
                ['superadmin', 'system_admin']
            ));

            if (empty($memberRoles)) return [];

            $companyNames = User::query()
                ->join('employees', 'employees.user_id', '=', 'users.id')
                ->whereIn('users.role', $memberRoles)
                ->whereNotNull('employees.company')
                ->where('employees.company', '!=', '')
                ->pluck('employees.company')
                ->unique()
                ->values()
                ->toArray();
        }

        if (empty($companyNames)) return [];

        // Resolve each (possibly variant-spelled) employee company name to a
        // canonical companies.id. employees.company is a free-text column — it
        // doesn't always match companies.name byte-for-byte. The most common
        // mismatch is trailing periods ("Enlinea Sdn. Bhd." in employees vs
        // "Enlinea Sdn Bhd" in companies). resolveCompanyId() handles that.
        $companyIds = [];
        foreach ($companyNames as $name) {
            $id = self::resolveCompanyId($name);
            if ($id) $companyIds[] = $id;
        }
        return array_values(array_unique($companyIds));
    }

    /**
     * Resolve a (possibly variant-spelled) company name to its canonical
     * companies.id. Tries exact match first, then a normalised match that
     * strips periods/commas and collapses whitespace. Returns null if no
     * company matches.
     *
     * Why this exists: employees.company is free-text and historic data has
     * "Enlinea Sdn. Bhd." while companies.name is "Enlinea Sdn Bhd". Without
     * normalisation, exact-match queries drop the entire Enlinea workforce
     * out of department auto-derive.
     */
    public static function resolveCompanyId(?string $name): ?int
    {
        if (empty($name)) return null;

        $exact = Company::where('name', $name)->value('id');
        if ($exact) return (int) $exact;

        $target = self::normaliseCompanyName($name);
        if ($target === '') return null;

        foreach (Company::all(['id', 'name']) as $c) {
            if (self::normaliseCompanyName($c->name) === $target) {
                return (int) $c->id;
            }
        }
        return null;
    }

    /** Normalise a company name for fuzzy comparison: lowercase, strip periods/commas, collapse whitespace. */
    public static function normaliseCompanyName(?string $name): string
    {
        if ($name === null) return '';
        $clean = str_replace(['.', ','], '', $name);
        $clean = preg_replace('/\s+/', ' ', $clean);
        return strtolower(trim($clean));
    }

    /**
     * Names of companies served by the given department. Convenient for queries
     * that match against employees.company (which stores name as a string).
     * Empty array = "serves all" (caller should treat as no restriction).
     *
     * Returns the canonical companies.name values AND any variant spellings
     * actually used in employees.company that map to those canonical companies.
     * Without the variants, downstream queries like
     *   whereIn('employees.company', $names)
     * miss employees whose company string differs by punctuation/whitespace
     * from the canonical name (e.g. "Enlinea Sdn. Bhd." vs "Enlinea Sdn Bhd").
     */
    public static function companyNamesServingDepartment(string $department): array
    {
        $ids = self::companiesServingDepartment($department);
        if (empty($ids)) return [];

        $canonical = Company::whereIn('id', $ids)->pluck('name')->toArray();

        // Pull every distinct variant of employees.company that normalises to
        // one of the canonical names. Small employees.company cardinality, fine
        // to do once per call.
        $canonicalNorm = array_map([self::class, 'normaliseCompanyName'], $canonical);
        $variants = Employee::whereNotNull('company')
            ->where('company', '!=', '')
            ->distinct()
            ->pluck('company')
            ->filter(fn($n) => in_array(self::normaliseCompanyName($n), $canonicalNorm, true))
            ->values()
            ->toArray();

        return array_values(array_unique(array_merge($canonical, $variants)));
    }

    /**
     * Returns the list of departments that serve the given company name.
     * Used to filter the Department dropdown when raising a ticket.
     *
     * STRICT membership: a dept is included only when the company is in its
     * served-companies cluster (auto-derived members ∪ pivot extras). The
     * implicit "serves-all" fallback for fully unconfigured depts is NOT
     * applied here — Implicitly-served depts intentionally don't appear in
     * any specific company's dropdown so users can't raise tickets to a dept
     * that has no actual members at this company. Superadmin/system_admin
     * bypass this filter at the controller (they always see all departments).
     */
    public static function departmentsForCompany(?string $companyName): array
    {
        if (empty($companyName)) {
            return self::DEPARTMENTS;
        }
        $companyId = self::resolveCompanyId($companyName);
        if (!$companyId) {
            return self::DEPARTMENTS;
        }

        $result = [];
        foreach (self::DEPARTMENTS as $dept) {
            $servedIds = self::companiesServingDepartment($dept);
            if (in_array($companyId, $servedIds, true)) {
                $result[] = $dept;
            }
        }
        return $result;
    }

    /**
     * True if the given department serves the given company name.
     *
     * STRICT membership (mirrors departmentsForCompany): the company must be
     * in the dept's served-companies cluster (auto-derived ∪ pivot extras).
     * The implicit "serves-all" fallback is NOT applied here so the form's
     * server-side validation matches what the dropdown shows the user.
     */
    public static function departmentServesCompany(string $department, ?string $companyName): bool
    {
        if (empty($companyName)) {
            return false;
        }
        $companyId = self::resolveCompanyId($companyName);
        if (!$companyId) {
            return false;
        }
        $servedIds = self::companiesServingDepartment($department);
        return in_array($companyId, $servedIds, true);
    }

    /**
     * Returns a User query for everyone eligible for the given department,
     * filtered to the companies that the department is configured to serve
     * (per the department_company_access pivot table).
     *
     * @param string $department         The ticket department.
     * @param bool   $includePicExtras   When true, also includes DEPARTMENT_PIC_EXTRA_ROLES
     *                                   (e.g. interns) — used for the PIC assignment pool.
     *                                   When false, returns managers only — used for
     *                                   new-ticket notifications and stale reminders.
     */
    public static function eligibleManagersQuery(string $department, bool $includePicExtras = false)
    {
        // Resolve served-company NAMES from the pivot. Empty = no restriction.
        $servedCompanies = self::companyNamesServingDepartment($department);

        $isWorkRoleDept = in_array($department, self::WORK_ROLE_MANAGER_DEPARTMENTS, true);
        $query = User::where('is_active', true);

        if ($isWorkRoleDept) {
            $query->where(function ($q) use ($department, $servedCompanies) {
                $q->whereHas('employee', function ($empQ) use ($department, $servedCompanies) {
                    $empQ->where('work_role', 'manager')
                         ->where('department', $department);
                    if (!empty($servedCompanies)) {
                        $empQ->whereIn('company', $servedCompanies);
                    }
                })->orWhereIn('role', ['superadmin', 'system_admin']);
            });
            return $query;
        }

        // App-role-gated department
        $managerRoles = self::DEPARTMENT_MANAGER_ROLES[$department] ?? [];
        $extraRoles   = $includePicExtras
            ? (self::DEPARTMENT_PIC_EXTRA_ROLES[$department] ?? [])
            : [];
        $deptRoles = array_values(array_unique(array_merge($managerRoles, $extraRoles)));

        if (!empty($servedCompanies)) {
            $query->where(function ($q) use ($deptRoles, $servedCompanies) {
                $q->where(function ($qq) use ($deptRoles, $servedCompanies) {
                    $qq->whereIn('role', $deptRoles)
                       ->whereHas('employee', function ($empQ) use ($servedCompanies) {
                           $empQ->whereIn('company', $servedCompanies);
                       });
                })->orWhereIn('role', ['superadmin', 'system_admin']);
            });
        } else {
            $query->whereIn('role', $deptRoles);
        }

        return $query;
    }

    /**
     * Generate a unique ticket number in the format TIC-YYYY-0001 (per-year sequence).
     * Uses a transaction with row-level lock to avoid race conditions.
     */
    public static function generateTicketNumber(): string
    {
        $year   = date('Y');
        $prefix = "TIC-{$year}-";

        return DB::transaction(function () use ($prefix) {
            $latest = static::where('ticket_number', 'like', $prefix . '%')
                ->lockForUpdate()
                ->orderByDesc('id')
                ->first();

            $nextSeq = 1;
            if ($latest) {
                $lastSeq = (int) substr($latest->ticket_number, strlen($prefix));
                $nextSeq = $lastSeq + 1;
            }

            return $prefix . str_pad((string) $nextSeq, 4, '0', STR_PAD_LEFT);
        });
    }

    /** Bootstrap method — auto-generate ticket_number on create when not set. */
    protected static function booted(): void
    {
        static::creating(function (Ticket $ticket) {
            if (empty($ticket->ticket_number)) {
                $ticket->ticket_number = self::generateTicketNumber();
            }
        });
    }

    /**
     * Returns a User query restricted to users eligible to be PIC for this ticket.
     * Includes managers + extra PIC-eligible roles (e.g. interns).
     * Scoped to the ticket's chosen company — only that company's dept members
     * are eligible (plus superadmin/sysadmin as catch-all).
     */
    public function eligiblePicQuery()
    {
        return self::picPoolForDeptAndCompany($this->department, $this->company_id, includePicExtras: true);
    }

    /**
     * Returns a User query of department managers (no interns) for this ticket.
     * Used for "new ticket" notifications and stale-ticket reminders when no
     * PIC is assigned. Same company-scoped behaviour as eligiblePicQuery.
     */
    public function managersForNotification()
    {
        return self::picPoolForDeptAndCompany($this->department, $this->company_id, includePicExtras: false);
    }

    /**
     * Returns an Employee query for managers whose HR record matches this
     * ticket's department but who haven't created (or have lost) their User
     * account yet — they are unreachable via managersForNotification() which
     * is User-keyed. Used as an email-only fallback so a ticket raised for a
     * dept whose manager is onboarded but not yet registered still pings them.
     *
     * Scope (only applies to work-role-gated depts — app-role-gated depts
     * identify managers via users.role, which doesn't exist pre-registration):
     *   - employees.work_role = 'manager'
     *   - employees.department = $this->department
     *   - employees.active_until IS NULL                  (current employment)
     *   - employees.company in dept's served cluster      (cross-company routing)
     *   - employees.company_email present
     *   - Either no users row at all, OR linked users row is inactive
     *     (filters out anyone already covered by managersForNotification()
     *     and avoids sending two emails to the same person)
     *
     * Bell notifications cannot reach these recipients — Laravel's
     * notifications.notifiable_id FKs to users.id — so call sites must email
     * only, no Notification::send().
     */
    public function unregisteredManagersForNotification()
    {
        if (!in_array($this->department, self::WORK_ROLE_MANAGER_DEPARTMENTS, true)) {
            return Employee::query()->whereRaw('1 = 0');
        }

        $servedCompanyNames = self::companyNamesServingDepartment($this->department);

        $q = Employee::where('work_role', 'manager')
            ->where('department', $this->department)
            ->whereNull('active_until')
            ->whereNotNull('company_email')
            ->where('company_email', '!=', '')
            ->where(function ($qq) {
                $qq->whereDoesntHave('user')
                   ->orWhereHas('user', fn($u) => $u->where('is_active', false));
            });

        if (!empty($servedCompanyNames)) {
            $q->whereIn('company', $servedCompanyNames);
        }

        return $q;
    }

    /**
     * Returns a User query for everyone eligible for tickets in the given
     * department. The pool is the dept's served-companies cluster (auto-derived
     * members ∪ pivot extras), NOT just the ticket's specific company.
     *
     * Two paths to inclusion (OR'd):
     *   1. Manager set — strict role/work_role check:
     *        - Work-role-gated: employees.work_role = 'manager' AND employees.department = $department
     *        - App-role-gated:  users.role ∈ DEPARTMENT_MANAGER_ROLES[$department]
     *                           (+ DEPARTMENT_PIC_EXTRA_ROLES like interns when $includePicExtras)
     *   2. Department membership (fallback, ONLY when $includePicExtras is true):
     *      employees.department = $department, regardless of work_role / users.role.
     *      Lets non-manager team members be assigned as PIC even though they're
     *      not in the manager set. Path 2 is intentionally NOT applied when
     *      $includePicExtras is false, so new-ticket / stale-reminder emails
     *      stay scoped to the manager set.
     *   PLUS superadmin/system_admin always eligible (catch-all).
     *
     * Both paths require employees.company to be in the dept's served cluster.
     *
     * The $companyId parameter is retained for call-site readability and as a
     * defensive signal (empty companyId still falls back to admins-only), but
     * it does NOT narrow the pool any further than the dept-served cluster.
     */
    public static function picPoolForDeptAndCompany(string $department, ?int $companyId, bool $includePicExtras = false)
    {
        $query = User::where('is_active', true);

        if (empty($companyId)) {
            return $query->whereIn('role', ['superadmin', 'system_admin']);
        }

        // Dept-served cluster (names — the employees.company column stores names,
        // not ids). Empty list = dept serves all companies → no company filter.
        $servedCompanyNames = self::companyNamesServingDepartment($department);

        $isWorkRoleDept = in_array($department, self::WORK_ROLE_MANAGER_DEPARTMENTS, true);

        $query->where(function ($outer) use ($department, $servedCompanyNames, $isWorkRoleDept, $includePicExtras) {
            // ── Path 1: Manager set ─────────────────────────────────────
            if ($isWorkRoleDept) {
                $outer->whereHas('employee', function ($empQ) use ($department, $servedCompanyNames) {
                    $empQ->where('work_role', 'manager')
                         ->where('department', $department);
                    if (!empty($servedCompanyNames)) {
                        $empQ->whereIn('company', $servedCompanyNames);
                    }
                });
            } else {
                $managerRoles = self::DEPARTMENT_MANAGER_ROLES[$department] ?? [];
                $extraRoles   = $includePicExtras
                    ? (self::DEPARTMENT_PIC_EXTRA_ROLES[$department] ?? [])
                    : [];
                $deptRoles = array_values(array_unique(array_merge($managerRoles, $extraRoles)));

                $outer->where(function ($qq) use ($deptRoles, $servedCompanyNames) {
                    $qq->whereIn('role', $deptRoles);
                    if (!empty($servedCompanyNames)) {
                        $qq->whereHas('employee', function ($empQ) use ($servedCompanyNames) {
                            $empQ->whereIn('company', $servedCompanyNames);
                        });
                    }
                });
            }

            // ── Path 2: Department membership (PIC dropdown only) ───────
            // Anyone whose employees.department exactly matches the dept name
            // and whose company is in the served cluster. Activated only when
            // $includePicExtras = true (i.e. the assign-PIC dropdown). Ensures
            // notification emails (which pass false) stay scoped to managers.
            if ($includePicExtras) {
                $outer->orWhereHas('employee', function ($empQ) use ($department, $servedCompanyNames) {
                    $empQ->where('department', $department);
                    if (!empty($servedCompanyNames)) {
                        $empQ->whereIn('company', $servedCompanyNames);
                    }
                });
            }

            // ── Catch-all: sysadmins always eligible ────────────────────
            $outer->orWhereIn('role', ['superadmin', 'system_admin']);
        });

        return $query;
    }

    /** Bootstrap badge color for status (used by views). */
    public function statusColor(): string
    {
        return match ($this->status) {
            'Open'        => 'secondary',
            'In Progress' => 'warning',
            'Pending'     => 'info',
            'Resolved'    => 'success',
            'Closed'      => 'dark',
            default       => 'secondary',
        };
    }

    /** True if the ticket is in a terminal (archived) status. */
    public function isArchivedStatus(): bool
    {
        return in_array($this->status, self::ARCHIVED_STATUSES, true);
    }

    /**
     * Time the PIC took to resolve the ticket — measured from when they were
     * assigned (assigned_at), not from creation. This is the right metric for
     * judging PIC performance: a ticket that sat unassigned for days isn't
     * the PIC's fault. Falls back to created_at for legacy tickets resolved
     * before the assigned_at column existed.
     *
     * Returns null when the ticket isn't terminal yet (no resolved_at).
     */
    public function timeToResolve(): ?string
    {
        if (!$this->resolved_at) {
            return null;
        }
        $start = $this->assigned_at ?? $this->created_at;
        if (!$start) {
            return null;
        }
        $diff = $start->diff($this->resolved_at);

        $parts = [];
        if ($diff->y > 0) $parts[] = $diff->y . 'y';
        if ($diff->m > 0) $parts[] = $diff->m . 'mo';
        if ($diff->d > 0) $parts[] = $diff->d . 'd';
        if ($diff->h > 0 && count($parts) < 2) $parts[] = $diff->h . 'h';
        if ($diff->i > 0 && count($parts) < 2) $parts[] = $diff->i . 'm';

        if (empty($parts)) return '< 1m';
        return implode(' ', array_slice($parts, 0, 2));
    }

    /**
     * Render a duration in minutes as the two most significant time units
     * (e.g. 3145 → "2d 4h", 75 → "1h 15m", 12 → "12m"). Used by analytics
     * to display avg resolution times.
     */
    public static function formatMinutes(int $minutes): string
    {
        if ($minutes <= 0) return '< 1m';

        $days  = intdiv($minutes, 1440);
        $hours = intdiv($minutes % 1440, 60);
        $mins  = $minutes % 60;

        $parts = [];
        if ($days > 0)  $parts[] = $days . 'd';
        if ($hours > 0) $parts[] = $hours . 'h';
        if ($mins > 0 && count($parts) < 2) $parts[] = $mins . 'm';

        if (empty($parts)) return '< 1m';
        return implode(' ', array_slice($parts, 0, 2));
    }

    /**
     * Maps an avg resolution time (minutes) to a health tier used to colour
     * the Department Health card.
     *
     * @return 'good' | 'amber' | 'poor' | 'nodata'
     */
    public static function healthTier(?int $minutes): string
    {
        if ($minutes === null || $minutes <= 0) return 'nodata';
        if ($minutes <= self::HEALTH_GOOD_MAX_MINUTES)  return 'good';
        if ($minutes <= self::HEALTH_AMBER_MAX_MINUTES) return 'amber';
        return 'poor';
    }

    public function priorityColor(): string
    {
        return match ($this->priority) {
            'Low'    => 'secondary',
            'Medium' => 'primary',
            'High'   => 'warning',
            'Urgent' => 'danger',
            default  => 'secondary',
        };
    }
}
