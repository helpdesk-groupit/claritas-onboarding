<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Ticket extends Model
{
    use HasFactory;

    protected $fillable = [
        'ticket_number', 'user_id', 'company_id', 'service_company_id',
        'assigned_to', 'assigned_at',
        'department', 'priority', 'status', 'subject', 'description',
        'resolved_at', 'last_reminder_sent_at',
    ];

    protected $casts = [
        'assigned_at' => 'datetime',
        'resolved_at' => 'datetime',
        'last_reminder_sent_at' => 'datetime',
    ];

    public const DEPARTMENTS = [
        // Core (pinned at top of dropdowns)
        'HRA', 'Group IT', 'Finance', 'Admin',
        // Extended (alphabetical)
        'Community', 'Consulting', 'Content', 'Design', 'Digital', 'Ecommerce',
        'KOL', 'Management', 'Marketing', 'Media', 'Production', 'Projects', 'Sales', 'Tech',
    ];

    public const PRIORITIES = ['Low', 'Medium', 'High', 'Urgent'];

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
    public const HEALTH_GOOD_MAX_MINUTES = 1440;   // 24 hours

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
        'HRA' => ['hr_manager', 'hr_executive', 'superadmin', 'system_admin'],
        'Group IT' => ['it_manager', 'it_executive', 'superadmin', 'system_admin'],
        'Finance' => ['finance_manager', 'finance_executive', 'superadmin', 'system_admin'],
        'Admin' => ['superadmin', 'system_admin'],
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
            'Claim Approval Request',
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
            'Claim Approval Request',
            'Other',
        ],
        'Finance' => [
            'Expense Reimbursement',
            'Invoice Query',
            'Vendor Payment',
            'Tax / Compliance Query',
            'Budget Request',
            'Claim Approval Request',
            'Other',
        ],
        'Admin' => [
            'Office Supplies',
            'Facility / Maintenance',
            'Travel Booking',
            'Meeting Room Booking',
            'General Enquiry',
            'Claim Approval Request',
            'Other',
        ],
        // Extended
        'Community' => [
            'Member Enquiry',
            'Event Coordination',
            'Communications Request',
            'Claim Approval Request',
            'Other',
        ],
        'Consulting' => [
            'Client Engagement',
            'Resource Allocation',
            'Project Scoping',
            'Claim Approval Request',
            'Other',
        ],
        'Content' => [
            'Content Request',
            'Editorial Review',
            'Publishing Issue',
            'Claim Approval Request',
            'Other',
        ],
        'Design' => [
            'Design Request',
            'Brand Asset Request',
            'Approval Required',
            'Claim Approval Request',
            'Other',
        ],
        'Digital' => [
            'Website Issue',
            'SEO / Analytics Query',
            'Digital Tool Access',
            'Claim Approval Request',
            'Other',
        ],
        'Ecommerce' => [
            'Order Issue',
            'Payment Issue',
            'Inventory Query',
            'Customer Complaint',
            'Claim Approval Request',
            'Other',
        ],
        'KOL' => [
            'Influencer Engagement',
            'Content Collaboration',
            'Campaign Brief',
            'Contract / Agreement',
            'Payment / Compensation',
            'Claim Approval Request',
            'Other',
        ],
        'Management' => [
            'Approval Request',
            'Policy Query',
            'Strategic Discussion',
            'Claim Approval Request',
            'Other',
        ],
        'Marketing' => [
            'Campaign Request',
            'Content Approval',
            'Brand Asset Request',
            'Analytics Query',
            'Claim Approval Request',
            'Other',
        ],
        'Media' => [
            'Media Request',
            'Press Enquiry',
            'Asset Distribution',
            'Claim Approval Request',
            'Other',
        ],
        'Production' => [
            'Equipment Issue',
            'Schedule Change',
            'Quality Issue',
            'Material Request',
            'Claim Approval Request',
            'Other',
        ],
        'Projects' => [
            'Project Status Update',
            'Resource Request',
            'Timeline Change',
            'Risk / Issue Report',
            'Claim Approval Request',
            'Other',
        ],
        'Sales' => [
            'Lead Query',
            'Pricing Approval',
            'Contract Review',
            'Commission Issue',
            'Claim Approval Request',
            'Other',
        ],
        'Tech' => [
            'Bug Report',
            'Feature Request',
            'Code Review Request',
            'Deployment Issue',
            'Performance Issue',
            'Claim Approval Request',
            'Other',
        ],
    ];

    /**
     * Keyword hints used to infer a department from a free-text "Other"
     * subject. First match wins (substring, case-insensitive). Order the
     * dictionaries narrowest → broadest if you add overlapping terms.
     *
     * NOTE: there is no separate Admin department in practice — HRA handles
     * office / facility / travel / meeting room queries too, so those
     * keywords are collected under HRA here.
     */
    public const SUBJECT_KEYWORD_HINTS = [
        // Digital sits FIRST so social-platform mentions win over generic IT
        // / Marketing terms. e.g. "Facebook password" routes to Digital (a
        // platform-specific query), not Group IT (which would match 'password').
        // Same reasoning for "Instagram ad campaign" vs Marketing's 'ad'/'campaign'.
        'Digital' => ['facebook', 'instagram', 'tiktok'],
        'Group IT' => ['laptop', 'computer', 'email', 'outlook', 'wifi', 'network',
            'password', 'login', 'access', 'software', 'install',
            'printer', 'vpn', 'monitor', 'mouse', 'keyboard'],
        'HRA' => ['salary', 'payroll', 'leave', 'onboard', 'offboard',
            'resignat', 'benefit', 'employ', 'contract',
            'office', 'supply', 'maintenance', 'facility',
            'travel', 'booking', 'meeting room', 'stationery'],
        'Finance' => ['invoice', 'payment', 'expense', 'reimburs', 'tax',
            'budget', 'vendor', 'claim', 'receipt'],
        'Marketing' => ['campaign', 'seo', 'analytics', 'marketing', 'ad ', 'ads '],
        'Design' => ['design', 'logo', 'mockup', 'figma', 'graphic'],
        'Tech' => ['bug', 'deploy', 'code review', 'api', 'performance issue',
            'server error'],
    ];

    /**
     * Build a reverse-lookup map: subject string → list of departments that
     * accept that subject. Excludes "Other" (handled separately).
     *
     * Most entries map to one department; a handful (e.g. "Brand Asset
     * Request") legitimately belong to multiple, in which case the optgroup
     * the user picked from disambiguates on the client side and this map is
     * used server-side to validate the submitted (subject, department) pair.
     */
    public static function subjectToDepartmentMap(): array
    {
        $map = [];
        foreach (self::DEPARTMENT_SUBJECTS as $dept => $subjects) {
            foreach ($subjects as $subject) {
                if ($subject === 'Other') {
                    continue;
                }
                $map[$subject][] = $dept;
            }
        }

        return $map;
    }

    /**
     * Server-side mirror of the client-side keyword inference used for
     * "Other" subjects. Returns the first matching department or null when
     * nothing in SUBJECT_KEYWORD_HINTS matches.
     */
    public static function inferDepartmentFromText(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }
        $lower = mb_strtolower(trim($text));
        if ($lower === '') {
            return null;
        }
        foreach (self::SUBJECT_KEYWORD_HINTS as $dept => $keywords) {
            foreach ($keywords as $kw) {
                if (str_contains($lower, $kw)) {
                    return $dept;
                }
            }
        }

        return null;
    }

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
        'HRA' => ['hr_intern'],
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

    /**
     * The company whose team is the service provider for this ticket
     * (i.e. the team that handles it). Distinct from company() (the raiser).
     */
    public function serviceCompany()
    {
        return $this->belongsTo(Company::class, 'service_company_id');
    }

    // ── Scopes ────────────────────────────────────────────────────────────

    /**
     * Restrict tickets to those visible on the Ticket Management page.
     *
     * Service-provider model:
     * - Superadmin / system_admin → everything (system-wide admins, bypass).
     * - Managers (incl. executives) → tickets in their managed department(s)
     *   where the SERVICE-PROVIDER company is the manager's own company. So
     *   a Group IT manager at Company A sees tickets whose service_company_id
     *   = A.id, regardless of which client raised them. Tickets routed to
     *   another company's Group IT team are invisible.
     * - Non-managers (interns assigned to tickets) → only tickets assigned to
     *   them as PIC. The assignment itself is the access grant.
     *
     * Legacy tickets where service_company_id IS NULL fall back to the old
     * cluster check on company_id — keeps pre-migration tickets visible to
     * the historical audience until they're edited / backfilled.
     *
     * Orphan-ticket safety net: a ticket whose service_company_id points at a
     * company that is NOT a valid provider for its department (the usual
     * cause is a superadmin-created ticket that fell back to an arbitrary
     * company, or a pre-fix re-route that never updated the column) would
     * otherwise be invisible to EVERY department manager. Such orphans are
     * surfaced to all managers of that department whose own company is a
     * legitimate provider — so a stranded Group IT ticket reappears for the
     * Group IT managers instead of living only in the superadmin's archive.
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
            return $query->where('assigned_to', $user->id);
        }

        // Manager's own company id — needed to match service_company_id.
        $managerCompanyId = $user->employee?->company
            ? self::resolveCompanyId($user->employee->company)
            : null;

        return $query->where(function ($outer) use ($managedDepartments, $managerCompanyId) {
            foreach ($managedDepartments as $dept) {
                $outer->orWhere(function ($inner) use ($dept, $managerCompanyId) {
                    $inner->where('tickets.department', $dept);

                    // Valid providers for this department — the source-company
                    // set (auto-derived members ∪ pivot extras). A ticket
                    // whose service_company_id is outside this set is an
                    // orphan and gets the safety-net treatment below.
                    $validProviderIds = self::sourceCompanyIdsForDepartment($dept);
                    $servedIds = self::companiesServingDepartment($dept);

                    $inner->where(function ($w) use ($managerCompanyId, $validProviderIds, $servedIds) {
                        // Strict (the common, correct path): my company is the
                        // ticket's service provider.
                        if ($managerCompanyId) {
                            $w->where('tickets.service_company_id', $managerCompanyId);
                        }

                        // Legacy fallback: pre-migration tickets (service
                        // company never set) keep the old cluster behaviour
                        // while the backfill catches up.
                        $w->orWhere(function ($leg) use ($servedIds) {
                            $leg->whereNull('tickets.service_company_id');
                            if (! empty($servedIds)) {
                                $leg->whereIn('tickets.company_id', $servedIds);
                            }
                        });

                        // Orphan safety net: service_company_id is set but is
                        // NOT a valid provider for this dept. Show it to every
                        // manager whose own company IS a valid provider, so
                        // mis-routed tickets surface to the dept team instead
                        // of vanishing. Skipped when the manager has no
                        // resolvable company, or the dept has no providers.
                        if ($managerCompanyId
                            && ! empty($validProviderIds)
                            && in_array($managerCompanyId, $validProviderIds, true)) {
                            $w->orWhere(function ($orphan) use ($validProviderIds) {
                                $orphan->whereNotNull('tickets.service_company_id')
                                    ->whereNotIn('tickets.service_company_id', $validProviderIds);
                            });
                        }
                    });
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
        if (! $companyId) {
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
        if (empty($managedDepartments)) {
            return;
        }

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
            if (! $emp || $emp->work_role !== 'manager' || $emp->department !== $department) {
                return false;
            }
            $userCompany = $emp->company;
        } else {
            $deptRoles = self::DEPARTMENT_MANAGER_ROLES[$department] ?? [];
            if (! in_array($user->role, $deptRoles, true)) {
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
            ->map(fn ($id) => (int) $id)
            ->toArray();

        return array_values(array_unique(array_merge($autoIds, $extraIds)));
    }

    /**
     * Public wrapper for defaultServedCompanyIdsForDepartment(). Used by the
     * source-column backfill migration; keeps the private method's
     * encapsulation while letting one-off scripts reuse the logic.
     */
    public static function defaultServedCompanyIdsForDepartmentPublic(string $department): array
    {
        return self::defaultServedCompanyIdsForDepartment($department);
    }

    // ── Service-provider resolution ────────────────────────────────────────

    /**
     * Returns the IDs of companies that have a team providing this department
     * (i.e. source / service-provider companies). Derived from auto-membership
     * — the same set used to populate Department Settings' company-first
     * accordion. UNION with any company referenced as `source_company_id` in
     * the pivot, so a company that's been explicitly named as a service
     * provider for another's tickets is still surfaced even if its own
     * auto-membership has been wiped.
     */
    public static function sourceCompanyIdsForDepartment(string $department): array
    {
        $autoIds = self::defaultServedCompanyIdsForDepartment($department);

        $pivotSourceIds = DepartmentCompanyAccess::where('department', $department)
            ->whereNotNull('source_company_id')
            ->pluck('source_company_id')
            ->map(fn ($id) => (int) $id)
            ->toArray();

        return array_values(array_unique(array_merge($autoIds, $pivotSourceIds)));
    }

    /**
     * Resolve the service-provider company for a (raiser_company, department)
     * pair. Returns:
     *   - int  → exactly one service provider; auto-route there
     *   - null → ambiguous OR none found; caller (UI) must prompt for a pick
     *
     * Priority order:
     *  1. If the raiser's own company has the department, route to itself.
     *     "Self-service when possible" was the chosen default (decided 2026-05-13).
     *  2. Pivot lookup: find rows where (department=$dept, company_id=$raiser).
     *     If exactly one distinct source_company_id, route there.
     *  3. Otherwise null — UI shows the Change picker with all (source, dept)
     *     pairs that COULD serve the raiser (auto-members + pivot extras).
     */
    public static function resolveServiceCompanyId(?int $raiserCompanyId, string $department): ?int
    {
        if ($raiserCompanyId === null) {
            return null;
        }

        // Rule 1: raiser self-service
        $sources = self::sourceCompanyIdsForDepartment($department);
        if (in_array($raiserCompanyId, $sources, true)) {
            return $raiserCompanyId;
        }

        // Rule 2: pivot says this raiser is served by N source companies
        $sourceCandidates = DepartmentCompanyAccess::where('department', $department)
            ->where('company_id', $raiserCompanyId)
            ->whereNotNull('source_company_id')
            ->pluck('source_company_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->toArray();

        if (count($sourceCandidates) === 1) {
            return $sourceCandidates[0];
        }

        return null;
    }

    /**
     * Pick the service-provider company for a ticket whose department is being
     * (re)assigned — used by the Edit-Department re-route and by the
     * stranded-ticket backfill migration.
     *
     * "Same company, new dept" rule: if the raiser's own company has a team
     * for $department, keep the ticket with that company — the most intuitive
     * outcome (a misfiled "Acme Tech" ticket becomes an "Acme Group IT"
     * ticket, not someone else's). Only when the raiser's company has no team
     * for the new dept do we fall back to the normal auto-resolution.
     *
     * Returns:
     *   - int  → a concrete service-provider company id
     *   - null → no provider could be determined (ambiguous, or none exists);
     *            the caller decides what to do (Edit form keeps the ticket
     *            visible to admins; the backfill leaves it untouched).
     *
     * @param  int|null  $raiserCompanyId  tickets.company_id (the client).
     * @param  string  $department  The department the ticket now belongs to.
     */
    public static function resolveServiceCompanyIdForDepartmentChange(?int $raiserCompanyId, string $department): ?int
    {
        if ($raiserCompanyId !== null) {
            // Raiser's own company has a team for the new dept → keep it local.
            $sources = self::sourceCompanyIdsForDepartment($department);
            if (in_array($raiserCompanyId, $sources, true)) {
                return $raiserCompanyId;
            }
        }

        // Otherwise defer to the standard auto-resolution (self-service rule,
        // then single-pivot-source rule). May still return null when ambiguous.
        return self::resolveServiceCompanyId($raiserCompanyId, $department);
    }

    /**
     * True when $serviceCompanyId is a legitimate service provider for
     * $department — i.e. it appears in the department's source-company set.
     *
     * Used to detect "stranded" tickets: a ticket whose service_company_id
     * points at a company that does NOT actually run that department (the
     * usual cause is a superadmin-created ticket that fell back to an
     * arbitrary company, or a re-route that never updated the column). Such
     * tickets are invisible to every department manager under the strict
     * scopeVisibleTo() match, so they need either backfill or the orphan
     * safety branch.
     *
     * A null $serviceCompanyId is considered NOT valid (stranded) so legacy
     * rows are caught too.
     */
    public static function isValidServiceCompanyForDepartment(?int $serviceCompanyId, string $department): bool
    {
        if ($serviceCompanyId === null) {
            return false;
        }

        return in_array($serviceCompanyId, self::sourceCompanyIdsForDepartment($department), true);
    }

    /**
     * Returns every (source_company_id, department) pair that COULD legitimately
     * serve the given raiser company — used to populate the Routing > Change
     * picker on the create form.
     *
     * For each department:
     *   - include the raiser's own company if it has the dept (self-service)
     *   - include every source explicitly mapped to this raiser via the pivot
     *
     * Shape: [['company_id' => 5, 'company_name' => 'Foo', 'department' => 'Tech'], ...]
     */
    public static function serviceOptionsForRaiser(?int $raiserCompanyId): array
    {
        if ($raiserCompanyId === null) {
            return [];
        }

        $pairs = [];

        foreach (self::DEPARTMENTS as $dept) {
            $deptSources = [];

            // Self-service: raiser's company has the dept
            $autoSources = self::defaultServedCompanyIdsForDepartment($dept);
            if (in_array($raiserCompanyId, $autoSources, true)) {
                $deptSources[] = $raiserCompanyId;
            }

            // Pivot: source companies explicitly serving this raiser for this dept
            $pivotSources = DepartmentCompanyAccess::where('department', $dept)
                ->where('company_id', $raiserCompanyId)
                ->whereNotNull('source_company_id')
                ->pluck('source_company_id')
                ->map(fn ($id) => (int) $id)
                ->toArray();

            $deptSources = array_values(array_unique(array_merge($deptSources, $pivotSources)));

            foreach ($deptSources as $companyId) {
                $pairs[] = ['company_id' => $companyId, 'department' => $dept];
            }
        }

        if (empty($pairs)) {
            return [];
        }

        $companyIds = array_unique(array_column($pairs, 'company_id'));
        $names = Company::whereIn('id', $companyIds)->pluck('name', 'id')->toArray();

        foreach ($pairs as &$pair) {
            $pair['company_name'] = $names[$pair['company_id']] ?? '(unknown)';
        }

        return $pairs;
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
            $extraRoles = self::DEPARTMENT_PIC_EXTRA_ROLES[$department] ?? [];
            $memberRoles = array_values(array_diff(
                array_merge($managerRoles, $extraRoles),
                ['superadmin', 'system_admin']
            ));

            if (empty($memberRoles)) {
                return [];
            }

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

        if (empty($companyNames)) {
            return [];
        }

        // Resolve each (possibly variant-spelled) employee company name to a
        // canonical companies.id. employees.company is a free-text column — it
        // doesn't always match companies.name byte-for-byte. The most common
        // mismatch is trailing periods ("Enlinea Sdn. Bhd." in employees vs
        // "Enlinea Sdn Bhd" in companies). resolveCompanyId() handles that.
        $companyIds = [];
        foreach ($companyNames as $name) {
            $id = self::resolveCompanyId($name);
            if ($id) {
                $companyIds[] = $id;
            }
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
        if (empty($name)) {
            return null;
        }

        $exact = Company::where('name', $name)->value('id');
        if ($exact) {
            return (int) $exact;
        }

        $target = self::normaliseCompanyName($name);
        if ($target === '') {
            return null;
        }

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
        if ($name === null) {
            return '';
        }
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
        if (empty($ids)) {
            return [];
        }

        $canonical = Company::whereIn('id', $ids)->pluck('name')->toArray();

        // Pull every distinct variant of employees.company that normalises to
        // one of the canonical names. Small employees.company cardinality, fine
        // to do once per call.
        $canonicalNorm = array_map([self::class, 'normaliseCompanyName'], $canonical);
        $variants = Employee::whereNotNull('company')
            ->where('company', '!=', '')
            ->distinct()
            ->pluck('company')
            ->filter(fn ($n) => in_array(self::normaliseCompanyName($n), $canonicalNorm, true))
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
        if (! $companyId) {
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
        if (! $companyId) {
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
     * @param  string  $department  The ticket department.
     * @param  bool  $includePicExtras  When true, also includes DEPARTMENT_PIC_EXTRA_ROLES
     *                                  (e.g. interns) — used for the PIC assignment pool.
     *                                  When false, returns managers only — used for
     *                                  new-ticket notifications and stale reminders.
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
                    if (! empty($servedCompanies)) {
                        $empQ->whereIn('company', $servedCompanies);
                    }
                })->orWhereIn('role', ['superadmin', 'system_admin']);
            });

            return $query;
        }

        // App-role-gated department
        $managerRoles = self::DEPARTMENT_MANAGER_ROLES[$department] ?? [];
        $extraRoles = $includePicExtras
            ? (self::DEPARTMENT_PIC_EXTRA_ROLES[$department] ?? [])
            : [];
        $deptRoles = array_values(array_unique(array_merge($managerRoles, $extraRoles)));

        if (! empty($servedCompanies)) {
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
        $year = date('Y');
        $prefix = "TIC-{$year}-";

        return DB::transaction(function () use ($prefix) {
            $latest = static::where('ticket_number', 'like', $prefix.'%')
                ->lockForUpdate()
                ->orderByDesc('id')
                ->first();

            $nextSeq = 1;
            if ($latest) {
                $lastSeq = (int) substr($latest->ticket_number, strlen($prefix));
                $nextSeq = $lastSeq + 1;
            }

            return $prefix.str_pad((string) $nextSeq, 4, '0', STR_PAD_LEFT);
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
     * Scoped to the SERVICE-PROVIDER company — only that company's dept members
     * are eligible (plus superadmin/sysadmin as catch-all). Falls back to the
     * raiser's company on legacy tickets where service_company_id is null.
     */
    public function eligiblePicQuery()
    {
        $serviceCompanyId = $this->service_company_id ?? $this->company_id;

        return self::picPoolForDeptAndCompany($this->department, $serviceCompanyId, includePicExtras: true);
    }

    /**
     * Returns a User query of department managers (no interns) for this ticket.
     * Used for "new ticket" notifications and stale-ticket reminders when no
     * PIC is assigned. Same service-company-scoped behaviour as eligiblePicQuery.
     */
    public function managersForNotification()
    {
        $serviceCompanyId = $this->service_company_id ?? $this->company_id;

        return self::picPoolForDeptAndCompany($this->department, $serviceCompanyId, includePicExtras: false);
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
        if (! in_array($this->department, self::WORK_ROLE_MANAGER_DEPARTMENTS, true)) {
            return Employee::query()->whereRaw('1 = 0');
        }

        // Narrow to the service-provider company's team. Legacy tickets
        // without service_company_id fall back to the raiser's company.
        $serviceCompanyId = $this->service_company_id ?? $this->company_id;
        $canonicalName = $serviceCompanyId
            ? Company::where('id', $serviceCompanyId)->value('name')
            : null;
        $serviceCompanyNames = $canonicalName ? self::companyNameVariants($canonicalName) : [];

        $q = Employee::where('work_role', 'manager')
            ->where('department', $this->department)
            ->whereNull('active_until')
            ->whereNotNull('company_email')
            ->where('company_email', '!=', '')
            ->where(function ($qq) {
                $qq->whereDoesntHave('user')
                    ->orWhereHas('user', fn ($u) => $u->where('is_active', false));
            });

        if (! empty($serviceCompanyNames)) {
            $q->whereIn('company', $serviceCompanyNames);
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
    /**
     * Returns a User query for everyone eligible for the given department at
     * the specific SERVICE-PROVIDER company. Unlike the previous cluster-wide
     * model, this narrows to a single source company's team — so a ticket
     * routed to Company A's Group IT is only assignable to A's Group IT
     * members (plus catch-all admins).
     *
     * $serviceCompanyId = the company whose team handles this ticket
     *                     (i.e. tickets.service_company_id), NOT the raiser.
     */
    public static function picPoolForDeptAndCompany(string $department, ?int $serviceCompanyId, bool $includePicExtras = false)
    {
        $query = User::where('is_active', true);

        if (empty($serviceCompanyId)) {
            return $query->whereIn('role', ['superadmin', 'system_admin']);
        }

        // Resolve the canonical and variant spellings of the service-provider
        // company name (employees.company is a free-text string).
        $canonicalName = Company::where('id', $serviceCompanyId)->value('name');
        $serviceCompanyNames = $canonicalName ? self::companyNameVariants($canonicalName) : [];

        $isWorkRoleDept = in_array($department, self::WORK_ROLE_MANAGER_DEPARTMENTS, true);

        $query->where(function ($outer) use ($department, $serviceCompanyNames, $isWorkRoleDept, $includePicExtras) {
            // ── Path 1: Manager set ─────────────────────────────────────
            if ($isWorkRoleDept) {
                $outer->whereHas('employee', function ($empQ) use ($department, $serviceCompanyNames) {
                    $empQ->where('work_role', 'manager')
                        ->where('department', $department);
                    if (! empty($serviceCompanyNames)) {
                        $empQ->whereIn('company', $serviceCompanyNames);
                    }
                });
            } else {
                $managerRoles = self::DEPARTMENT_MANAGER_ROLES[$department] ?? [];
                $extraRoles = $includePicExtras
                    ? (self::DEPARTMENT_PIC_EXTRA_ROLES[$department] ?? [])
                    : [];
                $deptRoles = array_values(array_unique(array_merge($managerRoles, $extraRoles)));

                $outer->where(function ($qq) use ($deptRoles, $serviceCompanyNames) {
                    $qq->whereIn('role', $deptRoles);
                    if (! empty($serviceCompanyNames)) {
                        $qq->whereHas('employee', function ($empQ) use ($serviceCompanyNames) {
                            $empQ->whereIn('company', $serviceCompanyNames);
                        });
                    }
                });
            }

            // ── Path 2: Department membership (PIC dropdown only) ───────
            if ($includePicExtras) {
                $outer->orWhereHas('employee', function ($empQ) use ($department, $serviceCompanyNames) {
                    $empQ->where('department', $department);
                    if (! empty($serviceCompanyNames)) {
                        $empQ->whereIn('company', $serviceCompanyNames);
                    }
                });
            }

            // ── Catch-all: sysadmins always eligible ────────────────────
            $outer->orWhereIn('role', ['superadmin', 'system_admin']);
        });

        return $query;
    }

    /**
     * Returns every spelling of a single company's name as it might appear in
     * `employees.company` (e.g. "Enlinea Sdn. Bhd." vs "Enlinea Sdn Bhd").
     * Used to constrain the PIC pool to one source company without missing
     * employees whose company string normalises differently from the
     * canonical `companies.name`.
     */
    public static function companyNameVariants(string $canonicalName): array
    {
        $canonicalNorm = self::normaliseCompanyName($canonicalName);
        $variants = Employee::whereNotNull('company')
            ->where('company', '!=', '')
            ->distinct()
            ->pluck('company')
            ->filter(fn ($n) => self::normaliseCompanyName($n) === $canonicalNorm)
            ->values()
            ->toArray();

        return array_values(array_unique(array_merge([$canonicalName], $variants)));
    }

    /**
     * The User account of the raiser's reporting manager, but ONLY when this
     * is a "same-department" ticket — i.e. the raiser filed it for their own
     * department (raiser's employees.department === this ticket's department).
     *
     * Used to send the raiser's direct line manager an extra new-ticket
     * notification (email + bell) on top of the standard department-manager
     * pool. This does NOT affect ticket visibility or the PIC pool — it is
     * purely an additional notification recipient.
     *
     * Returns null when:
     *   - the raiser has no employee record, or no department, or no manager_id
     *   - the ticket is cross-department (raiser's dept ≠ ticket department):
     *     cross-dept tickets route to the target department's head, not the
     *     raiser's own line manager
     *   - the resolved manager has no active User account (cannot be bell-
     *     notified; the caller emails them separately via the employee record)
     */
    public function reportingManagerForSameDeptNotification(): ?User
    {
        $raiserEmployee = $this->creator?->employee;
        if (! $raiserEmployee || empty($raiserEmployee->department) || empty($raiserEmployee->manager_id)) {
            return null;
        }

        // Same-department gate: only own-department tickets route to the
        // raiser's reporting manager.
        if ($raiserEmployee->department !== $this->department) {
            return null;
        }

        $managerEmployee = Employee::find($raiserEmployee->manager_id);
        if (! $managerEmployee) {
            return null;
        }

        $managerUser = $managerEmployee->user;
        if (! $managerUser || ! $managerUser->is_active) {
            return null;
        }

        return $managerUser;
    }

    /**
     * Same as reportingManagerForSameDeptNotification() but returns the
     * Employee record instead of the User — used for the email-only path when
     * the reporting manager has no (or an inactive) User account, mirroring
     * how unregisteredManagersForNotification() reaches managers by email.
     *
     * Returns null when there is no same-department reporting manager, or when
     * that manager DOES have an active User account (in which case the
     * User-returning method above already covers them — avoids double-emailing).
     */
    public function reportingManagerEmployeeForEmailOnly(): ?Employee
    {
        $raiserEmployee = $this->creator?->employee;
        if (! $raiserEmployee || empty($raiserEmployee->department) || empty($raiserEmployee->manager_id)) {
            return null;
        }
        if ($raiserEmployee->department !== $this->department) {
            return null;
        }

        $managerEmployee = Employee::find($raiserEmployee->manager_id);
        if (! $managerEmployee || empty($managerEmployee->company_email)) {
            return null;
        }

        // If they have an active User account they're covered by the
        // User-returning method — don't email them twice.
        $managerUser = $managerEmployee->user;
        if ($managerUser && $managerUser->is_active) {
            return null;
        }

        return $managerEmployee;
    }

    /** Bootstrap badge color for status (used by views). */
    public function statusColor(): string
    {
        return match ($this->status) {
            'Open' => 'secondary',
            'In Progress' => 'warning',
            'Pending' => 'info',
            'Resolved' => 'success',
            'Closed' => 'dark',
            default => 'secondary',
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
        if (! $this->resolved_at) {
            return null;
        }
        $start = $this->assigned_at ?? $this->created_at;
        if (! $start) {
            return null;
        }
        $diff = $start->diff($this->resolved_at);

        $parts = [];
        if ($diff->y > 0) {
            $parts[] = $diff->y.'y';
        }
        if ($diff->m > 0) {
            $parts[] = $diff->m.'mo';
        }
        if ($diff->d > 0) {
            $parts[] = $diff->d.'d';
        }
        if ($diff->h > 0 && count($parts) < 2) {
            $parts[] = $diff->h.'h';
        }
        if ($diff->i > 0 && count($parts) < 2) {
            $parts[] = $diff->i.'m';
        }

        if (empty($parts)) {
            return '< 1m';
        }

        return implode(' ', array_slice($parts, 0, 2));
    }

    /**
     * Render a duration in minutes as the two most significant time units
     * (e.g. 3145 → "2d 4h", 75 → "1h 15m", 12 → "12m"). Used by analytics
     * to display avg resolution times.
     */
    public static function formatMinutes(int $minutes): string
    {
        if ($minutes <= 0) {
            return '< 1m';
        }

        $days = intdiv($minutes, 1440);
        $hours = intdiv($minutes % 1440, 60);
        $mins = $minutes % 60;

        $parts = [];
        if ($days > 0) {
            $parts[] = $days.'d';
        }
        if ($hours > 0) {
            $parts[] = $hours.'h';
        }
        if ($mins > 0 && count($parts) < 2) {
            $parts[] = $mins.'m';
        }

        if (empty($parts)) {
            return '< 1m';
        }

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
        if ($minutes === null || $minutes <= 0) {
            return 'nodata';
        }
        if ($minutes <= self::HEALTH_GOOD_MAX_MINUTES) {
            return 'good';
        }
        if ($minutes <= self::HEALTH_AMBER_MAX_MINUTES) {
            return 'amber';
        }

        return 'poor';
    }

    public function priorityColor(): string
    {
        return match ($this->priority) {
            'Low' => 'secondary',
            'Medium' => 'primary',
            'High' => 'warning',
            'Urgent' => 'danger',
            default => 'secondary',
        };
    }
}
