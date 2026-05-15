<?php

namespace App\Http\Controllers;

use App\Mail\TicketAssignedMail;
use App\Mail\TicketCreatedMail;
use App\Mail\TicketResolvedMail;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketEditLog;
use App\Models\User;
use App\Notifications\TicketAssignedNotification;
use App\Notifications\TicketRaisedNotification;
use App\Notifications\TicketResolvedNotification;
use App\Notifications\TicketUnassignedNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

class TicketController extends Controller
{
    // ── Self-Service: tickets the user has raised ─────────────────────────
    // Used by everyone (employees, interns, managers, superadmin) for raising
    // new tickets and tracking their own. Managers/superadmin use /tickets/manage
    // for handling tickets they manage or are assigned to.
    //
    // Grouped by company → department in the view (same accordion as manage page)
    // so users who raise tickets across multiple companies see them organised.
    public function index(Request $request)
    {
        $user = Auth::user();

        // Tab scope: 'active' (default), 'assigned', or 'archived'
        // - 'active'   = tickets the user RAISED, status in ACTIVE_STATUSES
        // - 'assigned' = tickets the user is PIC of (any status; archived sorted last)
        // - 'archived' = tickets the user RAISED, status in ARCHIVED_STATUSES
        $scope = $request->query('scope', 'active');
        if (! in_array($scope, ['active', 'assigned', 'archived'], true)) {
            $scope = 'active';
        }

        // Tab counts (independent of current filter state)
        $counts = [
            'active' => Ticket::where('user_id', $user->id)
                ->whereIn('status', Ticket::ACTIVE_STATUSES)->count(),
            'assigned' => Ticket::where('assigned_to', $user->id)->count(),
            'archived' => Ticket::where('user_id', $user->id)
                ->whereIn('status', Ticket::ARCHIVED_STATUSES)->count(),
        ];

        $query = Ticket::with(['creator', 'assignee', 'company'])
            ->select('tickets.*')
            // Resolve a display company name once (form choice → companies.name,
            // fallback to creator's employees.company). See Ticket::scopeVisibleTo
            // for the matching dept-served cluster used elsewhere.
            ->addSelect(DB::raw(
                'COALESCE(
                    (SELECT name FROM companies WHERE companies.id = tickets.company_id),
                    (SELECT company FROM employees WHERE employees.user_id = tickets.user_id LIMIT 1)
                ) AS ticket_company_name'
            ))
            ->orderByRaw('CASE WHEN COALESCE(
                    (SELECT name FROM companies WHERE companies.id = tickets.company_id),
                    (SELECT company FROM employees WHERE employees.user_id = tickets.user_id LIMIT 1)
                ) IS NULL THEN 1 ELSE 0 END')
            ->orderByRaw('COALESCE(
                    (SELECT name FROM companies WHERE companies.id = tickets.company_id),
                    (SELECT company FROM employees WHERE employees.user_id = tickets.user_id LIMIT 1)
                ) ASC')
            ->orderBy('department')
            // FIELD() puts active statuses first, archived (Resolved/Closed) at
            // the bottom — matters most on the 'assigned' tab where both mix.
            ->orderByRaw("FIELD(status, 'Open', 'In Progress', 'Pending', 'Resolved', 'Closed')")
            ->orderByDesc('created_at');

        // Apply tab filter — three branches.
        if ($scope === 'assigned') {
            $query->where('assigned_to', $user->id);
            $statusOptions = Ticket::STATUSES;
        } elseif ($scope === 'archived') {
            $query->where('user_id', $user->id)
                ->whereIn('status', Ticket::ARCHIVED_STATUSES);
            $statusOptions = Ticket::ARCHIVED_STATUSES;
        } else {
            $query->where('user_id', $user->id)
                ->whereIn('status', Ticket::ACTIVE_STATUSES);
            $statusOptions = Ticket::ACTIVE_STATUSES;
        }

        // Optional status filter (constrained to the current tab's status set)
        if ($request->filled('status') && in_array($request->status, $statusOptions, true)) {
            $query->where('status', $request->status);
        }

        // Higher per-page since the page is collapsible — less risk of one
        // company being split across pages.
        $tickets = $query->paginate(100)->withQueryString();

        // Pre-group on the current page: Company → Department → Tickets
        $grouped = $tickets->getCollection()
            ->groupBy(function ($t) {
                return $t->ticket_company_name ?: '— Unassigned Company —';
            })
            ->map(fn ($byCompany) => $byCompany->groupBy('department'));

        // PIC analytics cards — only shown on the "Assigned to Me" tab.
        // Same shape as the manage-page analytics so we can reuse the partials.
        $analytics = null;
        if ($scope === 'assigned') {
            $analytics = $this->buildPicAnalytics($user);
        }

        return view('tickets.index', [
            'tickets' => $tickets,
            'grouped' => $grouped,
            'scope' => $scope,
            'counts' => $counts,
            'statusOptions' => $statusOptions,
            'analytics' => $analytics,
        ]);
    }

    // ── Ticket Management: tabs + company/dept grouping for managers/PICs ─
    public function manage(Request $request)
    {
        $user = Auth::user();
        if (! $user->canAccessTicketManagement()) {
            abort(403);
        }

        $scope = $request->query('scope', 'all');
        if (! in_array($scope, ['all', 'assigned', 'archived'], true)) {
            $scope = 'all';
        }

        // Closure returns a fresh base query with status/department filters applied.
        // Used for both the tab counts and the paginated result set.
        $base = function () use ($user, $request) {
            $q = Ticket::visibleTo($user);
            if ($request->filled('status') && in_array($request->status, Ticket::STATUSES, true)) {
                $q->where('status', $request->status);
            }
            if ($request->filled('department') && in_array($request->department, Ticket::DEPARTMENTS, true)) {
                $q->where('department', $request->department);
            }

            return $q;
        };

        $managedDepartments = Ticket::departmentsManagedBy($user);

        // Active tabs exclude terminal statuses; Archived tab shows only those.
        $counts = [
            'all' => $base()->whereIn('status', Ticket::ACTIVE_STATUSES)->count(),
            'assigned' => $base()->whereIn('status', Ticket::ACTIVE_STATUSES)->where('assigned_to', $user->id)->count(),
            'archived' => $base()->whereIn('status', Ticket::ARCHIVED_STATUSES)->count(),
        ];

        // Use the ticket's CHOSEN company (company_id from the create form) for
        // grouping, with creator's employee.company as a fallback for legacy rows
        // that pre-date the company_id column.
        $query = $base()
            ->with(['creator.employee', 'assignee', 'company'])
            ->select('tickets.*')
            // See note in index() — array-key alias on a DB::raw expression is
            // silently dropped by Laravel; embed AS directly in the raw SQL.
            ->addSelect(DB::raw(
                'COALESCE(
                    (SELECT name FROM companies WHERE companies.id = tickets.company_id),
                    (SELECT company FROM employees WHERE employees.user_id = tickets.user_id LIMIT 1)
                ) AS ticket_company_name'
            ))
            // Pin rows with no company at the bottom.
            ->orderByRaw('CASE WHEN COALESCE(
                    (SELECT name FROM companies WHERE companies.id = tickets.company_id),
                    (SELECT company FROM employees WHERE employees.user_id = tickets.user_id LIMIT 1)
                ) IS NULL THEN 1 ELSE 0 END')
            ->orderByRaw('COALESCE(
                    (SELECT name FROM companies WHERE companies.id = tickets.company_id),
                    (SELECT company FROM employees WHERE employees.user_id = tickets.user_id LIMIT 1)
                ) ASC')
            ->orderBy('department')
            ->orderByRaw("FIELD(status, 'Open', 'In Progress', 'Pending', 'Resolved', 'Closed')")
            ->orderByDesc('created_at');

        if ($scope === 'archived') {
            $query->whereIn('status', Ticket::ARCHIVED_STATUSES);
        } else {
            $query->whereIn('status', Ticket::ACTIVE_STATUSES);
            if ($scope === 'assigned') {
                $query->where('assigned_to', $user->id);
            }
        }

        // Higher per-page since the manage view is collapsible — less risk
        // of a single company being split across pages.
        $tickets = $query->paginate(100)->withQueryString();

        // Pre-group on the current page: Company → Department → Tickets.
        // Uses the ticket's chosen company (company_id), with creator's
        // employee.company as a legacy fallback.
        $grouped = $tickets->getCollection()
            ->groupBy(function ($t) {
                return $t->ticket_company_name ?: '— Unassigned Company —';
            })
            ->map(fn ($byCompany) => $byCompany->groupBy('department'));

        // For the superadmin table — one row per ticket needs its department's
        // manager list. Fetch once per unique department on the current page.
        // Excludes:
        //  - superadmin/system_admin (system-wide access, not org-level dept managers)
        //  - *_executive roles (they support the manager but aren't the manager themselves)
        // Result: only the actual department manager (hr_manager, it_manager,
        // finance_manager, or Employee.work_role = 'manager' for work-role-gated depts).
        $departmentManagers = [];
        if ($user->canViewAllTickets()) {
            $uniqueDepts = $tickets->getCollection()->pluck('department')->unique();
            $excludeRoles = ['superadmin', 'system_admin', 'hr_executive', 'it_executive', 'finance_executive'];
            foreach ($uniqueDepts as $dept) {
                $departmentManagers[$dept] = Ticket::eligibleManagersQuery($dept, false)
                    ->whereNotIn('users.role', $excludeRoles)
                    ->select('users.id', 'users.name', 'users.role')
                    ->orderBy('users.name')
                    ->get();
            }
        }

        // Analytics cards — superadmin sees system-wide snapshot, managers
        // see analytics scoped to their managed department(s). Both ignore
        // tab/filter state; they're a status-of-the-world dashboard.
        $analytics = null;
        if ($user->canViewAllTickets()) {
            $analytics = $this->buildAnalytics();
        } elseif (! empty($managedDepartments)) {
            $analytics = $this->buildManagerAnalytics($managedDepartments);
        }

        return view('tickets.manage', [
            'tickets' => $tickets,
            'grouped' => $grouped,
            'managedDepartments' => $managedDepartments,
            'scope' => $scope,
            'counts' => $counts,
            'departmentManagers' => $departmentManagers,
            'analytics' => $analytics,
        ]);
    }

    /**
     * Superadmin analytics dashboard.
     *  - Card 1: Active tickets by priority (system-wide)
     *  - Card 2: Avg resolution time by PIC (filterable by company)
     *  - Card 3: Department health based on avg resolution time (filterable by company)
     */
    private function buildAnalytics(): array
    {
        // Card 1 — Active tickets by priority (across the whole system)
        $byPriority = $this->countActiveByPriority(Ticket::query());

        // Cards 2 & 3 — Resolution-time stats. Computed across all Resolved
        // tickets in the system, broken out by company so the JS can filter.
        $allCompanies = Company::orderBy('name')->get(['id', 'name']);
        $resolutionData = $this->computeResolutionStats(
            Ticket::query(),     // unrestricted scope (superadmin)
            $allCompanies,
            null                 // no dept restriction
        );

        return array_merge([
            'mode' => 'superadmin',
            'totalActive' => array_sum($byPriority),
            'byPriority' => $byPriority,
        ], $resolutionData);
    }

    /**
     * Manager analytics dashboard. Scope expands beyond the manager's own
     * company — the analytics span ALL companies their managed department(s)
     * serve, so they can benchmark their company against others. The
     * operational ticket table below is unaffected.
     */
    private function buildManagerAnalytics(array $managedDepartments): array
    {
        // Card 1 — Active tickets by priority, scoped to managed depts only
        $card1Query = Ticket::whereIn('tickets.department', $managedDepartments);
        $byPriority = $this->countActiveByPriority($card1Query);

        // Companies dropdown: union of companies served by the user's managed depts
        $companyNamesUnion = collect();
        foreach ($managedDepartments as $dept) {
            $companyNamesUnion = $companyNamesUnion->merge(
                Ticket::companyNamesServingDepartment($dept)
            );
        }
        $availableCompanies = Company::whereIn('name', $companyNamesUnion->unique()->values())
            ->orderBy('name')
            ->get(['id', 'name']);

        // Cards 2 & 3 — Resolution-time stats across the broader served-companies scope
        $resolutionData = $this->computeResolutionStats(
            Ticket::whereIn('tickets.department', $managedDepartments),
            $availableCompanies,
            $managedDepartments
        );

        return array_merge([
            'mode' => 'manager',
            'totalActive' => array_sum($byPriority),
            'byPriority' => $byPriority,
            'managedDepartments' => $managedDepartments,
        ], $resolutionData);
    }

    /**
     * Active-ticket counts per priority. Returns an array keyed by every
     * priority in fixed order with zero defaults.
     */
    private function countActiveByPriority($baseQuery): array
    {
        $raw = (clone $baseQuery)
            ->whereIn('tickets.status', Ticket::ACTIVE_STATUSES)
            ->select('priority', DB::raw('COUNT(*) as cnt'))
            ->groupBy('priority')
            ->pluck('cnt', 'priority')
            ->toArray();

        $byPriority = [];
        foreach (Ticket::PRIORITIES as $p) {
            $byPriority[$p] = (int) ($raw[$p] ?? 0);
        }

        return $byPriority;
    }

    /**
     * Build Card 2 (PIC stats) and Card 3 (department health) data.
     *
     * @param  Builder  $baseQuery  Scope of tickets (unrestricted for
     *                              superadmin; managed-dept restricted
     *                              for managers).
     * @param  Collection  $availableCompanies  Companies shown in the filter dropdown.
     * @param  array|null  $deptList  Departments to enumerate for Card 3
     *                                (null = all DEPARTMENTS).
     */
    private function computeResolutionStats($baseQuery, $availableCompanies, ?array $deptList): array
    {
        // Limit to truly-resolved tickets only — Closed tickets weren't really
        // resolved, including them would skew the averages with stale-cleanup
        // events. Both must have resolved_at set for the SQL diff to work.
        $resolvedBase = (clone $baseQuery)
            ->where('tickets.status', 'Resolved')
            ->whereNotNull('tickets.resolved_at');

        // ── Card 2 — per (PIC, company) avg resolution time ─────────────
        $picRows = (clone $resolvedBase)
            ->whereNotNull('assigned_to')
            ->select(
                'assigned_to',
                'company_id',
                DB::raw('COUNT(*) as cnt'),
                DB::raw('AVG(TIMESTAMPDIFF(MINUTE, COALESCE(assigned_at, created_at), resolved_at)) as avg_minutes')
            )
            ->groupBy('assigned_to', 'company_id')
            ->get();

        // Resolve PIC names in one query
        $picIds = $picRows->pluck('assigned_to')->unique()->values();
        $picNames = User::whereIn('id', $picIds)->pluck('name', 'id');

        // Build per-company and aggregated structures
        // picStats = { '__all__' => [...], '<companyId>' => [...] }
        $picStats = ['__all__' => []];
        foreach ($availableCompanies as $c) {
            $picStats[(string) $c->id] = [];
        }

        // First aggregate per PIC across all companies for the "__all__" view
        $picAllAccum = []; // [picId => ['weightedSum' => x, 'totalCount' => y]]
        foreach ($picRows as $row) {
            $picId = (int) $row->assigned_to;
            $companyId = $row->company_id ? (string) $row->company_id : null;
            $cnt = (int) $row->cnt;
            $avgMinutes = (int) round((float) $row->avg_minutes);

            // Per-company entry
            if ($companyId !== null && isset($picStats[$companyId])) {
                $picStats[$companyId][] = $this->buildPerfRow(
                    ['name' => $picNames[$picId] ?? 'Unknown'], $cnt, $avgMinutes
                );
            }

            // Accumulate for "all companies"
            $picAllAccum[$picId] ??= ['weightedSum' => 0, 'totalCount' => 0];
            $picAllAccum[$picId]['weightedSum'] += $avgMinutes * $cnt;
            $picAllAccum[$picId]['totalCount'] += $cnt;
        }

        foreach ($picAllAccum as $picId => $acc) {
            $combinedAvg = (int) round($acc['weightedSum'] / max(1, $acc['totalCount']));
            $picStats['__all__'][] = $this->buildPerfRow(
                ['name' => $picNames[$picId] ?? 'Unknown'], $acc['totalCount'], $combinedAvg
            );
        }

        // Sort each list: fastest first
        foreach ($picStats as $key => $list) {
            usort($list, fn ($a, $b) => $a['avg_minutes'] <=> $b['avg_minutes']);
            $picStats[$key] = $list;
        }

        // ── Card 3 — per (department, company) avg resolution time + tier ──
        $deptRows = (clone $resolvedBase)
            ->select(
                'department',
                'company_id',
                DB::raw('COUNT(*) as cnt'),
                DB::raw('AVG(TIMESTAMPDIFF(MINUTE, COALESCE(assigned_at, created_at), resolved_at)) as avg_minutes')
            )
            ->groupBy('department', 'company_id')
            ->get();

        $deptList = $deptList ?: Ticket::DEPARTMENTS;

        // Initialise every (company, dept) combo with no-data so the card always
        // shows a complete table even if a dept hasn't resolved anything yet.
        // Per-company rows are seeded ONLY for depts that actually exist at
        // that company (auto-derive ∪ pivot extras) — otherwise the company
        // filter would list depts that aren't relevant to that company.
        // The "__all__" view still shows every dept across the system.
        $deptStats = ['__all__' => []];
        foreach ($availableCompanies as $c) {
            $deptStats[(string) $c->id] = [];
        }
        $emptyEntry = $this->buildPerfRow(['department' => null], 0, null);

        // Pre-resolve served-companies per dept once (each call hits the DB).
        $deptServedCompanyIds = [];
        foreach ($deptList as $dept) {
            $deptServedCompanyIds[$dept] = Ticket::companiesServingDepartment($dept);
        }

        foreach ($deptList as $dept) {
            $entry = array_merge($emptyEntry, ['department' => $dept]);
            $deptStats['__all__'][$dept] = $entry;
            foreach ($availableCompanies as $c) {
                if (in_array((int) $c->id, $deptServedCompanyIds[$dept], true)) {
                    $deptStats[(string) $c->id][$dept] = $entry;
                }
            }
        }

        $deptAllAccum = []; // [dept => ['weightedSum' => x, 'totalCount' => y]]
        foreach ($deptRows as $row) {
            $dept = $row->department;
            if (! in_array($dept, $deptList, true)) {
                continue;
            }
            $companyId = $row->company_id ? (string) $row->company_id : null;
            $cnt = (int) $row->cnt;
            $avgMinutes = (int) round((float) $row->avg_minutes);

            // Only fill the per-company cell if we seeded it (i.e. the dept
            // actually serves this company per the current cluster config).
            // A ticket whose (dept, company) is no longer in the cluster
            // — e.g. dropped extras, member moved away — gets skipped from
            // the per-company view but still aggregates into __all__ below.
            if ($companyId !== null && isset($deptStats[$companyId][$dept])) {
                $deptStats[$companyId][$dept] = $this->buildPerfRow(
                    ['department' => $dept], $cnt, $avgMinutes
                );
            }

            $deptAllAccum[$dept] ??= ['weightedSum' => 0, 'totalCount' => 0];
            $deptAllAccum[$dept]['weightedSum'] += $avgMinutes * $cnt;
            $deptAllAccum[$dept]['totalCount'] += $cnt;
        }

        foreach ($deptAllAccum as $dept => $acc) {
            $combinedAvg = (int) round($acc['weightedSum'] / max(1, $acc['totalCount']));
            $deptStats['__all__'][$dept] = $this->buildPerfRow(
                ['department' => $dept], $acc['totalCount'], $combinedAvg
            );
        }

        // Sort each list: by tier (good→amber→poor→nodata) then by avg ASC
        $tierOrder = ['good' => 1, 'amber' => 2, 'poor' => 3, 'nodata' => 4];
        foreach ($deptStats as $key => $list) {
            $arr = array_values($list);
            usort($arr, function ($a, $b) use ($tierOrder) {
                $tierCmp = ($tierOrder[$a['tier']] ?? 9) <=> ($tierOrder[$b['tier']] ?? 9);
                if ($tierCmp !== 0) {
                    return $tierCmp;
                }

                return ($a['avg_minutes'] ?? PHP_INT_MAX) <=> ($b['avg_minutes'] ?? PHP_INT_MAX);
            });
            $deptStats[$key] = $arr;
        }

        // Tier summaries for the small "tier counts" header on Card 3 —
        // one per filter scope.
        $deptTierCounts = [];
        foreach ($deptStats as $key => $list) {
            $counts = ['good' => 0, 'amber' => 0, 'poor' => 0, 'nodata' => 0];
            foreach ($list as $entry) {
                $counts[$entry['tier']] = ($counts[$entry['tier']] ?? 0) + 1;
            }
            $deptTierCounts[$key] = $counts;
        }

        return [
            'picStats' => $picStats,             // { '__all__'|companyId => [{name, count, avg_minutes, formatted, tier, width_pct}] }
            'deptStats' => $deptStats,            // { '__all__'|companyId => [{department, count, avg_minutes, formatted, tier, width_pct}] }
            'deptTierCounts' => $deptTierCounts,       // { '__all__'|companyId => {good, amber, poor, nodata} }
            'availableCompanies' => $availableCompanies->map(fn ($c) => ['id' => (string) $c->id, 'name' => $c->name])->values()->toArray(),
        ];
    }

    /**
     * Analytics for the PIC view (My Tickets > Assigned to Me tab).
     *
     * Mirrors the shape of buildAnalytics()/buildManagerAnalytics() so the
     * existing card partials (analytics-card-2-pic-times,
     * analytics-card-3-dept-health) can be reused unchanged. Data is scoped
     * to the user, not their team:
     *   - Card 1 (priority)   — active tickets ASSIGNED TO this user.
     *   - Card 2 (PIC perf)   — only this user as a PIC, no others.
     *   - Card 3 (dept health) — only the user's own dept (employees.department).
     */
    private function buildPicAnalytics(User $user): array
    {
        // ── Card 1: my active tickets, by priority ─────────────────────
        $byPriority = $this->countActiveByPriority(
            Ticket::where('assigned_to', $user->id)
        );

        // ── Card 2: my own PIC performance ────────────────────────────
        $myStats = Ticket::where('assigned_to', $user->id)
            ->where('status', 'Resolved')
            ->whereNotNull('resolved_at')
            ->selectRaw('COUNT(*) AS cnt, AVG(TIMESTAMPDIFF(MINUTE, COALESCE(assigned_at, created_at), resolved_at)) AS avg_minutes')
            ->first();

        $myCount = (int) ($myStats->cnt ?? 0);
        $myAvgMinutes = $myCount > 0 ? (int) round((float) $myStats->avg_minutes) : null;

        $picStats = ['__all__' => []];
        if ($myCount > 0) {
            $picStats['__all__'][] = $this->buildPerfRow(
                ['name' => $user->name],
                $myCount,
                $myAvgMinutes
            );
        }

        // ── Card 3: my department's health ─────────────────────────────
        $myDept = $user->employee?->department;
        $deptStats = ['__all__' => []];
        $deptTierCounts = ['__all__' => ['good' => 0, 'amber' => 0, 'poor' => 0, 'nodata' => 0]];

        if ($myDept) {
            $deptRow = Ticket::where('department', $myDept)
                ->where('status', 'Resolved')
                ->whereNotNull('resolved_at')
                ->selectRaw('COUNT(*) AS cnt, AVG(TIMESTAMPDIFF(MINUTE, COALESCE(assigned_at, created_at), resolved_at)) AS avg_minutes')
                ->first();

            $deptCount = (int) ($deptRow->cnt ?? 0);
            $deptAvgMinutes = $deptCount > 0 ? (int) round((float) $deptRow->avg_minutes) : null;

            $entry = $this->buildPerfRow(
                ['department' => $myDept],
                $deptCount,
                $deptAvgMinutes
            );
            $deptStats['__all__'][] = $entry;
            $deptTierCounts['__all__'][$entry['tier']]++;
        }

        return [
            'mode' => 'pic',
            'totalActive' => array_sum($byPriority),
            'byPriority' => $byPriority,
            'picStats' => $picStats,
            'deptStats' => $deptStats,
            'deptTierCounts' => $deptTierCounts,
            // No company-filter dropdown for the PIC view — it's a one-row card.
            'availableCompanies' => [],
        ];
    }

    /**
     * Build a row entry for the perf cards (Card 2 PIC + Card 3 dept).
     * Includes pre-computed bar width % and tier so the view doesn't need maths.
     *
     * Bar scales 0-100% against HEALTH_AMBER_MAX_MINUTES — anything beyond the
     * amber/poor boundary fills the bar fully. Visual: longer bar = slower.
     */
    private function buildPerfRow(array $base, int $count, ?int $avgMinutes): array
    {
        $widthPct = 0;
        if ($avgMinutes !== null && $avgMinutes > 0) {
            $widthPct = min(100, ($avgMinutes / Ticket::HEALTH_AMBER_MAX_MINUTES) * 100);
        }

        return array_merge($base, [
            'count' => $count,
            'avg_minutes' => $avgMinutes,
            'formatted' => $avgMinutes !== null ? Ticket::formatMinutes($avgMinutes) : '—',
            'tier' => Ticket::healthTier($avgMinutes),
            'width_pct' => $widthPct,
        ]);
    }

    // ── Create form ───────────────────────────────────────────────────────
    public function create()
    {
        $user = Auth::user();
        $userCompany = $user->employee?->company;

        // All registered companies — needed for the fallback Company dropdown
        // (when the raiser has no employee record) AND for the service-options
        // lookup that powers the Routing > Change picker.
        $companies = Company::orderBy('name')->get(['id', 'name']);

        $autoCompanyId = $userCompany ? Ticket::resolveCompanyId($userCompany) : null;
        $autoCompanyName = $autoCompanyId
            ? optional($companies->firstWhere('id', $autoCompanyId))->name
            : null;

        $defaultCompanyId = $autoCompanyId ?? $companies->first()?->id;

        // Available service-provider options for THIS raiser — every (source
        // company, dept) pair that could legitimately handle their tickets.
        // The Change picker on the form uses this; for each dept, the JS
        // narrows the list to the matching options (usually one, sometimes
        // multiple, occasionally none).
        $serviceOptions = $autoCompanyId
            ? Ticket::serviceOptionsForRaiser($autoCompanyId)
            : [];

        // Per-(subject_token, dept) auto-resolved service company — used by the
        // JS to populate the Routing preview without a server round-trip.
        // Shape: [department => service_company_id|null]. null = ambiguous,
        // requires the user to use the Change picker.
        $resolvedServiceByDept = [];
        if ($autoCompanyId) {
            foreach (Ticket::DEPARTMENTS as $dept) {
                $resolvedServiceByDept[$dept] = Ticket::resolveServiceCompanyId($autoCompanyId, $dept);
            }
        }

        return view('tickets.create', [
            'companies' => $companies,
            'autoCompanyId' => $autoCompanyId,
            'autoCompanyName' => $autoCompanyName,
            'defaultCompanyId' => $defaultCompanyId,
            'priorities' => Ticket::PRIORITIES,
            'departmentSubjects' => Ticket::DEPARTMENT_SUBJECTS,
            'subjectToDepartments' => Ticket::subjectToDepartmentMap(),
            'keywordHints' => Ticket::SUBJECT_KEYWORD_HINTS,
            'departmentsAll' => Ticket::DEPARTMENTS,
            'serviceOptions' => $serviceOptions,
            'resolvedServiceByDept' => $resolvedServiceByDept,
        ]);
    }

    // ── Store new ticket ──────────────────────────────────────────────────
    public function store(Request $request)
    {
        $user = Auth::user();

        $data = $request->validate([
            'company_id' => 'required|exists:companies,id',
            'service_company_id' => 'nullable|exists:companies,id',
            'subject' => 'required|string|max:255',
            'subject_other' => 'nullable|string|max:255',
            'description' => 'required|string|max:10000',
            'department' => 'required|in:'.implode(',', Ticket::DEPARTMENTS),
            'priority' => 'required|in:'.implode(',', Ticket::PRIORITIES),
            'attachments' => 'nullable|array|max:10',
            'attachments.*' => 'file|max:10240|mimes:pdf,jpg,jpeg,png,gif,webp|valid_file_content',
        ]);

        // Company (raiser/client) auto-override — same as before: if the
        // raiser has an employee record, force the ticket onto their own
        // company regardless of what the client submitted.
        $userCompany = $user->employee?->company;
        if ($userCompany) {
            $autoCompanyId = Ticket::resolveCompanyId($userCompany);
            if ($autoCompanyId) {
                $data['company_id'] = $autoCompanyId;
            }
        }

        $companyName = Company::where('id', $data['company_id'])->value('name');

        // Subject-driven department resolution.
        // For standardised subjects: re-derive department from the
        //   subject→dept map (defence in depth — the client picked from a
        //   specific optgroup, but we don't trust hand-crafted POSTs). For
        //   subjects that map to multiple departments (e.g. "Brand Asset
        //   Request" → Design or Marketing), accept the client's choice if
        //   it's in the valid set.
        // For "Other": trust the client-submitted department (set via
        //   keyword inference or the manual override picker). Sanity-check
        //   that keyword inference would have picked the same dept, but only
        //   to log mismatches — don't reject.
        if ($data['subject'] !== 'Other') {
            $map = Ticket::subjectToDepartmentMap();
            if (! isset($map[$data['subject']])) {
                return back()
                    ->withErrors(['subject' => 'Selected subject is not recognised.'])
                    ->withInput();
            }
            $validDepts = $map[$data['subject']];
            if (! in_array($data['department'], $validDepts, true)) {
                if (count($validDepts) === 1) {
                    // Auto-correct single-dept subjects (client likely tampered)
                    $data['department'] = $validDepts[0];
                } else {
                    return back()
                        ->withErrors(['department' => 'Please pick which department should handle this subject.'])
                        ->withInput();
                }
            }
        } else {
            $custom = trim($data['subject_other'] ?? '');
            if ($custom === '') {
                return back()
                    ->withErrors(['subject_other' => 'Please describe the subject when picking "Other".'])
                    ->withInput();
            }
        }

        // Resolve the service-provider company for this (raiser, dept) pair.
        // Priority: client-submitted service_company_id (if it's a legitimate
        // candidate for this raiser) > auto-resolve via Ticket::resolveServiceCompanyId.
        $validServiceOptions = Ticket::serviceOptionsForRaiser($data['company_id']);
        $allowedServiceIdsForDept = array_values(array_unique(array_column(
            array_filter($validServiceOptions, fn ($p) => $p['department'] === $data['department']),
            'company_id'
        )));

        $serviceCompanyId = null;

        if (! empty($data['service_company_id'])) {
            $clientPick = (int) $data['service_company_id'];
            if (in_array($clientPick, $allowedServiceIdsForDept, true)) {
                $serviceCompanyId = $clientPick;
            } elseif ($user->isSuperadmin() || $user->isSystemAdmin()) {
                // Admins can route to any company that has the dept's team.
                $sourcePool = Ticket::sourceCompanyIdsForDepartment($data['department']);
                if (in_array($clientPick, $sourcePool, true)) {
                    $serviceCompanyId = $clientPick;
                }
            }
        }

        if ($serviceCompanyId === null) {
            $serviceCompanyId = Ticket::resolveServiceCompanyId($data['company_id'], $data['department']);
        }

        if ($serviceCompanyId === null) {
            if ($user->isSuperadmin() || $user->isSystemAdmin()) {
                // Admin without an explicit pick — fall back to the raiser's
                // own company if the dept exists there, else any source.
                $sourcePool = Ticket::sourceCompanyIdsForDepartment($data['department']);
                $serviceCompanyId = in_array($data['company_id'], $sourcePool, true)
                    ? $data['company_id']
                    : ($sourcePool[0] ?? null);
            }
        }

        if ($serviceCompanyId === null) {
            return back()
                ->withErrors(['service_company_id' => 'No service provider is configured for this department for your company. Please use the Change picker to choose one, or contact a superadmin.'])
                ->withInput();
        }

        $finalSubject = $data['subject'];
        if ($data['subject'] === 'Other') {
            $finalSubject = 'Other — '.trim($data['subject_other']);
        }

        $ticket = Ticket::create([
            'user_id' => $user->id,
            'company_id' => $data['company_id'],
            'service_company_id' => $serviceCompanyId,
            'department' => $data['department'],
            'priority' => $data['priority'],
            'subject' => $finalSubject,
            'description' => $data['description'],
            'status' => 'Open',
        ]);

        // Process and store attachments (image-compress + secure save)
        if ($request->hasFile('attachments')) {
            foreach ($request->file('attachments') as $file) {
                $this->storeTicketAttachment($ticket, $file);
            }
        }

        // Notify department managers of the new ticket (email + in-app bell).
        // Excludes interns — they only get notified when actually assigned.
        $managers = $ticket->managersForNotification()->get();

        // Same-department tickets also notify the raiser's reporting manager
        // (their direct line manager). This is notification-only — it does NOT
        // change ticket visibility or the PIC pool. Skipped for cross-dept
        // tickets, and de-duplicated against the department-manager pool above
        // so a reporting manager who is also a dept manager isn't pinged twice.
        $reportingManager = $ticket->reportingManagerForSameDeptNotification();
        if ($reportingManager && ! $managers->contains('id', $reportingManager->id)) {
            $managers->push($reportingManager);
        }

        foreach ($managers as $manager) {
            if ($manager->work_email) {
                Mail::to($manager->work_email)->queue(new TicketCreatedMail($ticket, $manager));
            }
        }
        Notification::send($managers, new TicketRaisedNotification($ticket->fresh(['creator'])));

        // Email-only fallback for unregistered managers — HR records exist but
        // the User row hasn't been created yet, so they're invisible to the
        // User-keyed query above. The bell ping can't reach them (notifications
        // table FKs to users) until they register.
        $unregisteredEmployeeIds = [];
        foreach ($ticket->unregisteredManagersForNotification()->get() as $unregEmp) {
            Mail::to($unregEmp->company_email)->queue(new TicketCreatedMail($ticket, $unregEmp));
            $unregisteredEmployeeIds[] = $unregEmp->id;
        }

        // Same-dept reporting manager who has no (or an inactive) User account
        // — email-only, same as the unregistered-manager fallback. Skipped if
        // already emailed as an unregistered department manager just above.
        $reportingManagerEmp = $ticket->reportingManagerEmployeeForEmailOnly();
        if ($reportingManagerEmp && ! in_array($reportingManagerEmp->id, $unregisteredEmployeeIds, true)) {
            Mail::to($reportingManagerEmp->company_email)->queue(new TicketCreatedMail($ticket, $reportingManagerEmp));
        }

        return redirect()->route('tickets.show', $ticket)->with('success', 'Ticket created.');
    }

    // ── Ticket detail / chat view ─────────────────────────────────────────
    public function show(Request $request, Ticket $ticket)
    {
        $user = Auth::user();
        $this->authorizeView($user, $ticket);

        $ticket->load(['creator.employee', 'assignee', 'messages.sender', 'attachments']);

        // Manage controls (Add/Remove PIC + Update Status as a manager) are
        // only surfaced when the user navigated here from the Ticket Management
        // page (which appends ?from=manage to its links). If a manager or
        // superadmin clicks the same ticket from /tickets (My Tickets), they
        // were acting as the raiser — keep it read-only there. The PIC's own
        // status-update path stays open regardless of source page (see view).
        $hasManageRole = $user->canManageTicketsForDepartment($ticket->department)
                         || $user->isSuperadmin() || $user->isSystemAdmin();
        $cameFromManagePage = $request->query('from') === 'manage';
        $canManage = $hasManageRole && $cameFromManagePage;

        // Only fetch the eligible-PIC pool when the assign-PIC dropdown will render.
        $assigneePool = collect();
        if ($canManage) {
            $assigneePool = $ticket->eligiblePicQuery()
                ->orderBy('name')
                ->get();
        }

        return view('tickets.show', [
            'ticket' => $ticket,
            'assigneePool' => $assigneePool,
            'canManage' => $canManage,
            'statuses' => Ticket::STATUSES,
        ]);
    }

    // ── Re-route a misfiled ticket (manager / superadmin only) ────────────
    // The only editable field is the department — used when a raiser picked
    // the wrong dept. Changing it clears PIC + assigned_at, resets status to
    // Open, and re-fires the new-ticket notifications to the new dept's pool.
    // The edit log is recorded for every change; only superadmin/system_admin
    // see it on the ticket detail page (gated in the view).
    public function editAdmin(Ticket $ticket)
    {
        $this->authorizeEdit($ticket);

        $ticket->load('attachments', 'company', 'creator');

        return view('tickets.edit-admin', [
            'ticket' => $ticket,
            'companies' => Company::orderBy('name')->get(['id', 'name']),
            'departments' => Ticket::DEPARTMENTS,
            'priorities' => Ticket::PRIORITIES,
        ]);
    }

    public function updateAdmin(Request $request, Ticket $ticket)
    {
        $this->authorizeEdit($ticket);

        $data = $request->validate([
            'department' => 'required|in:'.implode(',', Ticket::DEPARTMENTS),
            'note' => 'nullable|string|max:1000',
        ]);

        if ($data['department'] === $ticket->department) {
            return redirect()->route('tickets.show', ['ticket' => $ticket, 'from' => 'manage'])
                ->with('info', 'No changes were saved — department is the same.');
        }

        $changes = [
            'department' => ['from' => $ticket->department, 'to' => $data['department']],
        ];

        // Re-route also has to move the ticket onto the NEW department's
        // service-provider company. Without this, service_company_id keeps
        // pointing at the OLD dept's provider — and Ticket::scopeVisibleTo()
        // (which matches a manager's company against service_company_id)
        // would strand the ticket: invisible to every manager of the new
        // department. "Same company, new dept" — keep it with the raiser's
        // own company when that company runs the new dept, else auto-resolve.
        $newServiceCompanyId = Ticket::resolveServiceCompanyIdForDepartmentChange(
            $ticket->company_id,
            $data['department']
        );
        // Fall back to the existing value when nothing could be resolved, so
        // we never blank out an already-valid routing into NULL.
        $newServiceCompanyId ??= $ticket->service_company_id;

        if ($newServiceCompanyId !== $ticket->service_company_id) {
            $changes['service_company_id'] = [
                'from' => $ticket->service_company_id,
                'to' => $newServiceCompanyId,
            ];
        }

        DB::transaction(function () use ($ticket, $data, $changes, $newServiceCompanyId) {
            // Old PIC is no longer in the new dept's eligible pool. Clear PIC +
            // assigned_at so the new dept managers take it from scratch. Status
            // returns to Open so the new owners see it as fresh.
            $ticket->update([
                'department' => $data['department'],
                'service_company_id' => $newServiceCompanyId,
                'assigned_to' => null,
                'assigned_at' => null,
                'status' => 'Open',
            ]);

            TicketEditLog::create([
                'ticket_id' => $ticket->id,
                'edited_by_user_id' => Auth::id(),
                'changes' => $changes,
                'note' => $data['note'] ?? null,
            ]);
        });

        // Refresh so notification helpers see the new dept on the model.
        $ticket->refresh();

        // Notify the *new* department's managers as if the ticket had just been
        // raised in their queue. Old dept managers are not re-notified — the
        // ticket leaves their inbox by virtue of the dept-scoped visibility.
        $managers = $ticket->managersForNotification()->get();

        // If the re-routed department now matches the raiser's own department,
        // their reporting manager is notified too (notification-only, same
        // rule as ticket creation). De-duplicated against the dept-manager set.
        $reportingManager = $ticket->reportingManagerForSameDeptNotification();
        if ($reportingManager && ! $managers->contains('id', $reportingManager->id)) {
            $managers->push($reportingManager);
        }

        foreach ($managers as $manager) {
            if ($manager->work_email) {
                Mail::to($manager->work_email)->queue(new TicketCreatedMail($ticket, $manager));
            }
        }
        if ($managers->isNotEmpty()) {
            Notification::send($managers, new TicketRaisedNotification($ticket->fresh(['creator'])));
        }
        $unregisteredEmployeeIds = [];
        foreach ($ticket->unregisteredManagersForNotification()->get() as $unregEmp) {
            Mail::to($unregEmp->company_email)->queue(new TicketCreatedMail($ticket, $unregEmp));
            $unregisteredEmployeeIds[] = $unregEmp->id;
        }
        $reportingManagerEmp = $ticket->reportingManagerEmployeeForEmailOnly();
        if ($reportingManagerEmp && ! in_array($reportingManagerEmp->id, $unregisteredEmployeeIds, true)) {
            Mail::to($reportingManagerEmp->company_email)->queue(new TicketCreatedMail($ticket, $reportingManagerEmp));
        }

        // After a dept change, the editor may no longer have manage rights on
        // the *new* department — and authorizeView() would 403 the redirect to
        // the show page. So if they've routed themselves out of their own
        // access, send them back to the Ticket Management list instead.
        $user = Auth::user();
        $stillHasAccess = $user->isSuperadmin()
            || $user->isSystemAdmin()
            || $user->canManageTicketsForDepartment($ticket->department);

        if ($stillHasAccess) {
            return redirect()->route('tickets.show', ['ticket' => $ticket, 'from' => 'manage'])
                ->with('success', 'Department updated. The new department\'s managers have been notified.');
        }

        return redirect()->route('tickets.manage')
            ->with('success', 'Ticket moved to '.$ticket->department.'. Its new department\'s managers have been notified, and it is no longer in your inbox.');
    }

    /**
     * Permission gate for the Edit Department action. Superadmin/system_admin
     * always; otherwise must be a manager of the ticket's *current* department
     * (so a Tech manager can re-route a misfiled Tech ticket to Group IT, but
     * a Group IT manager couldn't grab a KOL ticket they have no business with).
     */
    private function authorizeEdit(Ticket $ticket): void
    {
        $user = Auth::user();
        $allowed = $user
            && ($user->isSuperadmin()
                || $user->isSystemAdmin()
                || $user->canManageTicketsForDepartment($ticket->department));
        if (! $allowed) {
            abort(403);
        }
    }

    // ── Manager assigns PIC (mirrors ItTaskController@assignPic logic) ────
    public function assignPic(Request $request, Ticket $ticket)
    {
        $user = Auth::user();
        if (! $user->canManageTicketsForDepartment($ticket->department)
            && ! $user->isSuperadmin() && ! $user->isSystemAdmin()) {
            abort(403);
        }

        $picUserId = $request->input('assigned_pic_user_id');

        // Remove PIC — clear assignment, return to Open. Also clear assigned_at
        // so the next PIC's clock starts fresh from their own assignment time.
        if (! $picUserId) {
            $previousPic = $ticket->assigned_to ? User::find($ticket->assigned_to) : null;
            $ticket->update(['assigned_to' => null, 'assigned_at' => null, 'status' => 'Open']);
            if ($previousPic) {
                $previousPic->notify(new TicketUnassignedNotification($ticket->fresh(), $user));
            }

            return back()->with('success', 'PIC removed.');
        }

        // Direct PIC-to-PIC switching is not allowed.
        // The manager must first remove the existing PIC, then assign a new one.
        if ($ticket->assigned_to && (int) $picUserId !== (int) $ticket->assigned_to) {
            return back()->with('error', 'Remove the current PIC before assigning a new one.');
        }

        $request->validate(['assigned_pic_user_id' => 'required|exists:users,id']);

        // Confirm chosen user is eligible — honours department scope (global vs company).
        $ticket->load('creator.employee');
        $isEligible = $ticket->eligiblePicQuery()
            ->where('users.id', $picUserId)
            ->exists();
        if (! $isEligible) {
            return back()->withErrors(['assigned_pic_user_id' => 'Selected user is not eligible to be PIC for this ticket.']);
        }

        $candidate = User::findOrFail($picUserId);

        // Assigning a PIC moves the ticket into the active "In Progress" state,
        // regardless of whether it was Open or Pending. Only preserve already-
        // terminal statuses (Resolved/Closed) — those should be re-opened
        // explicitly via the status dropdown, not by assigning a PIC.
        $newStatus = in_array($ticket->status, Ticket::ARCHIVED_STATUSES, true)
            ? $ticket->status
            : 'In Progress';

        // Stamp assigned_at on this transition. Resolution-time analytics use
        // this as the start of the PIC's clock instead of the ticket's
        // creation timestamp — see TicketController::computeResolutionStats
        // and Ticket::timeToResolve().
        $ticket->update([
            'assigned_to' => $picUserId,
            'assigned_at' => now(),
            'status' => $newStatus,
        ]);

        Mail::to($candidate->work_email)->queue(new TicketAssignedMail($ticket->fresh(['creator', 'assignee']), $candidate));
        $candidate->notify(new TicketAssignedNotification($ticket->fresh(), $user));

        return back()->with('success', 'PIC assigned.');
    }

    // ── Update ticket status ──────────────────────────────────────────────
    public function updateStatus(Request $request, Ticket $ticket)
    {
        $user = Auth::user();
        $isManager = $user->canManageTicketsForDepartment($ticket->department)
                     || $user->isSuperadmin() || $user->isSystemAdmin();
        $isAssignee = $ticket->assigned_to === $user->id;

        if (! $isManager && ! $isAssignee) {
            abort(403);
        }

        $request->validate([
            'status' => 'required|in:'.implode(',', Ticket::STATUSES),
        ]);

        $previousStatus = $ticket->status;
        $newStatus = $request->status;

        // Block setting "In Progress" on a ticket with no PIC — it's auto-set
        // when a PIC is assigned, not user-selectable in that state.
        if ($newStatus === 'In Progress' && empty($ticket->assigned_to)) {
            return back()->withErrors(['status' => 'In Progress requires an assigned PIC. Assign a PIC first.']);
        }

        // Resolution event: transitioning INTO Resolved from a non-terminal state.
        // Triggers the resolution email + bell notification to the creator.
        $isResolutionEvent = $newStatus === 'Resolved'
            && ! in_array($previousStatus, Ticket::ARCHIVED_STATUSES, true);

        $update = ['status' => $newStatus];

        // Set resolved_at the first time we enter Resolved
        if ($newStatus === 'Resolved' && empty($ticket->resolved_at)) {
            $update['resolved_at'] = now();
        }
        // Re-opening from a terminal state — clear resolved_at
        elseif (in_array($previousStatus, Ticket::ARCHIVED_STATUSES, true)
                && ! in_array($newStatus, Ticket::ARCHIVED_STATUSES, true)) {
            $update['resolved_at'] = null;
        }

        $ticket->update($update);

        if ($isResolutionEvent && $ticket->creator) {
            Mail::to($ticket->creator->work_email)->queue(new TicketResolvedMail($ticket->fresh(['creator', 'assignee'])));
            $ticket->creator->notify(new TicketResolvedNotification($ticket->fresh(['creator', 'assignee'])));
        }

        return back()->with('success', $isResolutionEvent
            ? 'Ticket resolved.'
            : 'Ticket status updated.');
    }

    // ── Authorization helper ──────────────────────────────────────────────
    private function authorizeView(User $user, Ticket $ticket): void
    {
        if ($user->isSuperadmin() || $user->isSystemAdmin()) {
            return;
        }
        if ($ticket->user_id === $user->id || $ticket->assigned_to === $user->id) {
            return;
        }
        if ($user->canManageTicketsForDepartment($ticket->department)) {
            return;
        }
        abort(403);
    }

    /**
     * Persist a single uploaded file as a TicketAttachment via AttachmentProcessor.
     */
    private function storeTicketAttachment(Ticket $ticket, $file): void
    {
        $meta = \App\Services\AttachmentProcessor::store(
            $file,
            'ticket_attachments',
            $ticket->id.'_'
        );
        TicketAttachment::create(array_merge(['ticket_id' => $ticket->id], $meta));
    }
}
