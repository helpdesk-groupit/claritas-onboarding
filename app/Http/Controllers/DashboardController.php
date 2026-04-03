<?php

namespace App\Http\Controllers;

use App\Models\Announcement;
use App\Models\Onboarding;
use App\Models\Employee;
use App\Models\AssetInventory;
use App\Models\WorkDetail;
use App\Models\User;
use App\Models\Aarf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class DashboardController extends Controller
{
    private function groupCompanyName(string $name): string
    {
        static $registered = null;
        if ($registered === null) {
            $registered = \App\Models\Company::orderBy('name')->pluck('name')->toArray();
        }
        $trimmed = trim($name);
        // Exact match (case-insensitive)
        foreach ($registered as $r) {
            if (strcasecmp($trimmed, $r) === 0) return $r;
        }
        return 'Unspecified';
    }

    private function groupedCompanyCollection($rows, string $companyField = 'company'): array
    {
        $grouped = [];
        foreach ($rows as $row) {
            $key = $this->groupCompanyName($row->$companyField ?? 'Unknown');
            $grouped[$key] = ($grouped[$key] ?? 0) + $row->total;
        }
        return collect($grouped)->map(fn($t,$c) => (object)[$companyField=>$c,'total'=>$t])->values()->all();
    }

    // ── Shared stat data (HR + IT) ─────────────────────────────────────────
    private function getDashboardStats(): array
    {
        $now = Carbon::now();

        // Total onboard year to date
        $rawOnboardingsByCompany = WorkDetail::select('company', DB::raw('count(*) as total'))
            ->whereYear('start_date', $now->year)
            ->groupBy('company')->orderByDesc('total')->get();

        // New joiners this month
        $rawNewJoiners = WorkDetail::select('company', DB::raw('count(*) as total'))
            ->whereMonth('start_date', $now->month)->whereYear('start_date', $now->year)
            ->groupBy('company')->get();

        // Exiting this month — from offboardings table (source of truth for exits)
        $exitingByCompany = \App\Models\Offboarding::select('company', DB::raw('count(*) as total'))
            ->whereNotNull('exit_date')
            ->whereMonth('exit_date', $now->month)->whereYear('exit_date', $now->year)
            ->groupBy('company')->get();

        // Active employees — base query
        $activeQuery = fn() => Employee::whereNull('active_until');

        $activeByCompany = $activeQuery()
            ->select('company', DB::raw('count(*) as total'))
            ->groupBy('company')->get();

        $activeByDesignation = $activeQuery()
            ->select('designation', DB::raw('count(*) as total'))
            ->groupBy('designation')->orderByDesc('total')->get();

        $activeByRole = $activeQuery()
            ->select('work_role', DB::raw('count(*) as total'))
            ->groupBy('work_role')->orderByDesc('total')->get();

        $activeByDepartment = $activeQuery()
            ->select('department', DB::raw('count(*) as total'))
            ->groupBy('department')->orderByDesc('total')->get();

        $birthdayBabies = Employee::whereNull('active_until')
            ->whereNotNull('date_of_birth')
            ->whereMonth('date_of_birth', $now->month)
            ->orderByRaw('DAY(date_of_birth) ASC')
            ->get(['full_name', 'preferred_name', 'date_of_birth', 'designation', 'department', 'company']);

        return [
            'stats' => [
                'total_onboardings_ytd'  => WorkDetail::whereYear('start_date', $now->year)->count(),
                'new_joiners_this_month' => WorkDetail::whereMonth('start_date', $now->month)->whereYear('start_date', $now->year)->count(),
                'exiting_this_month'     => \App\Models\Offboarding::whereNotNull('exit_date')->whereMonth('exit_date', $now->month)->whereYear('exit_date', $now->year)->count(),
                'active_employees'       => Employee::whereNull('active_until')->count(),
            ],
            'onboardingsByCompany'   => $this->groupedCompanyCollection($rawOnboardingsByCompany),
            'newJoinersByCompany'    => $this->groupedCompanyCollection($rawNewJoiners),
            'exitingByCompany'       => $this->groupedCompanyCollection($exitingByCompany),
            'activeByCompany'        => $this->groupedCompanyCollection($activeByCompany),
            'activeByDesignation'    => $activeByDesignation,
            'activeByRole'           => $activeByRole,
            'activeByDepartment'     => $activeByDepartment,
            'birthdayBabies'         => $birthdayBabies,
        ];
    }

    private function getAnnouncements(?string $company): \Illuminate\Database\Eloquent\Collection
    {
        return Announcement::with('creator.employee')
            ->visibleTo($company)
            ->orderByDesc('created_at')
            ->limit(5)
            ->get();
    }

    public function hrDashboard()
    {
        $user = Auth::user();
        if (!$user->isHr() && !$user->isSuperadmin() && !$user->isSystemAdmin()) {
            return redirect()->route('user.dashboard');
        }
        extract($this->getDashboardStats());
        $latestAnnouncements = $this->getAnnouncements($user->employee?->company);

        // Asset overview — needed for superadmin view on HR dashboard
        $assetStats = [
            'total_assets'      => AssetInventory::count(),
            'available'         => AssetInventory::where('status', 'available')->count(),
            'assigned'          => AssetInventory::whereIn('status', ['assigned','unavailable'])->count(),
            'under_maintenance' => AssetInventory::where('status', 'under_maintenance')->count(),
        ];
        $assetsByType = AssetInventory::selectRaw('asset_type, count(*) as total')
            ->groupBy('asset_type')->orderByDesc('total')->get();

        $companyOwnedTotal = AssetInventory::where('ownership_type', 'company')->count();
        $rawCompanyOwned = AssetInventory::where('ownership_type', 'company')
            ->selectRaw('COALESCE(NULLIF(TRIM(company_name),""), "Unspecified") as company, count(*) as total')
            ->groupBy('company')->orderByDesc('total')->get();
        $companyOwnedByCompany = $this->groupedCompanyCollection($rawCompanyOwned);

        $rentalTotal = AssetInventory::where('ownership_type', 'rental')->count();
        $rawRentalByVendor = AssetInventory::where('ownership_type', 'rental')
            ->selectRaw('COALESCE(NULLIF(TRIM(rental_vendor),""), "Unspecified") as vendor, count(*) as total')
            ->groupBy('vendor')->orderByDesc('total')->get();
        $rentalByVendor = $this->groupedCompanyCollection($rawRentalByVendor, 'vendor');

        return view('hr.dashboard', compact(
            'stats','onboardingsByCompany','newJoinersByCompany','exitingByCompany',
            'activeByCompany','activeByDesignation','activeByRole','activeByDepartment',
            'assetStats','assetsByType','companyOwnedTotal','companyOwnedByCompany',
            'rentalTotal','rentalByVendor','birthdayBabies','latestAnnouncements'
        ));
    }

    public function itDashboard()
    {
        $user = Auth::user();
        if (!$user->isIt() && !$user->isSuperadmin()) return redirect()->route('user.dashboard');

        // ── Card 1: Overall Assets — breakdown by type ────────────────────
        $assetStats = [
            'total_assets'      => AssetInventory::count(),
            'available'         => AssetInventory::where('status', 'available')->count(),
            'assigned'          => AssetInventory::whereIn('status', ['assigned','unavailable'])->count(),
            'under_maintenance' => AssetInventory::where('status', 'under_maintenance')->count(),
        ];

        $assetsByType = AssetInventory::selectRaw('asset_type, count(*) as total')
            ->groupBy('asset_type')
            ->orderByDesc('total')
            ->get();

        // ── Card 2: Company Owned — breakdown by company_name ─────────────
        $companyOwnedTotal = AssetInventory::where('ownership_type', 'company')->count();
        $rawCompanyOwned = AssetInventory::where('ownership_type', 'company')
            ->selectRaw('COALESCE(NULLIF(TRIM(company_name),""), "Unspecified") as company, count(*) as total')
            ->groupBy('company')
            ->orderByDesc('total')
            ->get();
        $companyOwnedByCompany = $this->groupedCompanyCollection($rawCompanyOwned);

        // ── Card 3: Rental — breakdown by vendor ─────────────────────────
        $rentalTotal = AssetInventory::where('ownership_type', 'rental')->count();
        $rawRentalByVendor = AssetInventory::where('ownership_type', 'rental')
            ->selectRaw('COALESCE(NULLIF(TRIM(rental_vendor),""), "Unspecified") as vendor, count(*) as total')
            ->groupBy('vendor')
            ->orderByDesc('total')
            ->get();
        $rentalByVendor = $this->groupedCompanyCollection($rawRentalByVendor, 'vendor');

        extract($this->getDashboardStats());

        // Recent onboarding records (pending/active, upcoming start dates)
        $recentOnboardings = Onboarding::with(['personalDetail','workDetail','aarf'])
            ->whereIn('status', ['pending', 'active'])
            ->latest()
            ->limit(10)
            ->get();

        $latestAnnouncements = $this->getAnnouncements($user->employee?->company);

        return view('it.dashboard', compact(
            'assetStats', 'assetsByType',
            'companyOwnedTotal', 'companyOwnedByCompany',
            'rentalTotal', 'rentalByVendor',
            'stats', 'onboardingsByCompany', 'newJoinersByCompany', 'exitingByCompany',
            'activeByCompany', 'activeByDesignation', 'activeByRole', 'activeByDepartment',
            'recentOnboardings', 'birthdayBabies', 'latestAnnouncements'
        ));
    }

    // ── IT Onboarding list ─────────────────────────────────────────────────
    public function itOnboarding(Request $request)
    {
        $user = Auth::user();
        if (!$user->isIt() && !$user->isSuperadmin()) return redirect()->route('user.dashboard');

        $query = Onboarding::with(['personalDetail','workDetail','aarf','assignedPic']);

        // Only hide past-start-date records when no filter is active.
        // When the user searches by name or filters by date/company/position,
        // past records (already activated employees) become visible too.
        $hasFilter = $request->filled('search')
                  || $request->filled('company')
                  || $request->filled('position')
                  || $request->filled('start_date_from')
                  || $request->filled('start_date_to');

        if (!$hasFilter) {
            $query->whereHas('workDetail', fn($q) => $q->where('start_date', '>=', now()->toDateString()))
                  ->whereDoesntHave('employee');
        }

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function($q) use ($s) {
                $q->whereHas('personalDetail', fn($q2) => $q2->where('full_name','like',"%{$s}%"))
                  ->orWhereHas('workDetail', fn($q2) =>
                      $q2->where('designation','like',"%{$s}%")->orWhere('company','like',"%{$s}%")
                  );
            });
        }
        if ($request->filled('company'))         $query->whereHas('workDetail', fn($q) => $q->where('company','like',"%{$request->company}%"));
        if ($request->filled('position'))        $query->whereHas('workDetail', fn($q) => $q->where('designation','like',"%{$request->position}%"));
        if ($request->filled('start_date_from')) $query->whereHas('workDetail', fn($q) => $q->where('start_date','>=',$request->start_date_from));
        if ($request->filled('start_date_to'))   $query->whereHas('workDetail', fn($q) => $q->where('start_date','<=',$request->start_date_to));

        $onboardings = $query->latest()->paginate(15)->withQueryString();
        $companies   = WorkDetail::distinct()->pluck('company');
        // IT staff for PIC dropdown — include IT Manager, executive, intern
        // Exclude: offboarded (active_until set) OR exit date has already passed
        $itStaff = User::whereIn('role', ['it_manager', 'it_executive', 'it_intern'])
            ->where('is_active', true)
            ->whereDoesntHave('employee', fn($q) => $q->where(function ($q2) {
                $q2->whereNotNull('active_until')
                   ->orWhere(fn($q3) => $q3->whereNotNull('exit_date')->where('exit_date', '<', now()->toDateString()));
            }))
            ->orderBy('name')
            ->get();

        return view('it.onboarding', compact('onboardings','companies','itStaff'));
    }

    public function userDashboard()
    {
        $user = Auth::user();
        if ($user->isHr() || $user->isSuperadmin() || $user->isSystemAdmin()) return redirect()->route('hr.dashboard');
        if ($user->isIt()) return redirect()->route('it.dashboard');

        $employee = $user->employee?->load('onboarding.personalDetail','onboarding.workDetail');
        $birthdayBabies = Employee::whereNull('active_until')
            ->whereNotNull('date_of_birth')
            ->whereMonth('date_of_birth', Carbon::now()->month)
            ->orderByRaw('DAY(date_of_birth) ASC')
            ->get(['full_name', 'preferred_name', 'date_of_birth', 'designation', 'department', 'company']);
        $latestAnnouncements = $this->getAnnouncements($employee?->company);
        return view('user.dashboard', compact('user','employee','birthdayBabies','latestAnnouncements'));
    }
}