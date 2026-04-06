<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\Onboarding;
use App\Models\Offboarding;
use App\Models\AssetInventory;
use App\Models\AssetAssignment;
use App\Models\WorkDetail;
use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ReportController extends Controller
{
    /** Only superadmin + hr_manager can access reports */
    private function authorize(): void
    {
        $user = Auth::user();
        if (!$user->isSuperadmin() && !$user->isHrManager() && !$user->isSystemAdmin()) {
            abort(403);
        }
    }

    // ═══════════════════════════════════════════════════════════════════
    // EXECUTIVE DASHBOARD — all KPIs on one page
    // ═══════════════════════════════════════════════════════════════════
    public function executiveDashboard(Request $request)
    {
        $this->authorize();

        $now = Carbon::now();
        $year = (int) $request->input('year', $now->year);
        $companies = Company::orderBy('name')->pluck('name')->toArray();
        $companyFilter = $request->input('company');

        // ── Workforce KPIs ─────────────────────────────────────────────
        $activeQ = Employee::whereNull('active_until');
        if ($companyFilter) $activeQ = $activeQ->where('company', $companyFilter);
        $totalActive = $activeQ->count();

        $newHiresYear = WorkDetail::whereYear('start_date', $year);
        if ($companyFilter) $newHiresYear = $newHiresYear->where('company', $companyFilter);
        $totalNewHires = $newHiresYear->count();

        $exitsYear = Offboarding::whereNotNull('exit_date')->whereYear('exit_date', $year);
        if ($companyFilter) $exitsYear = $exitsYear->where('company', $companyFilter);
        $totalExits = $exitsYear->count();

        $turnoverRate = $totalActive > 0 ? round(($totalExits / $totalActive) * 100, 1) : 0;

        // Monthly headcount trend (new hires vs exits per month)
        $monthlyHires = WorkDetail::selectRaw('MONTH(start_date) as m, COUNT(*) as total')
            ->whereYear('start_date', $year)
            ->when($companyFilter, fn($q) => $q->where('company', $companyFilter))
            ->groupByRaw('MONTH(start_date)')->pluck('total', 'm')->toArray();

        $monthlyExits = Offboarding::selectRaw('MONTH(exit_date) as m, COUNT(*) as total')
            ->whereNotNull('exit_date')->whereYear('exit_date', $year)
            ->when($companyFilter, fn($q) => $q->where('company', $companyFilter))
            ->groupByRaw('MONTH(exit_date)')->pluck('total', 'm')->toArray();

        $headcountTrend = [];
        for ($m = 1; $m <= 12; $m++) {
            $headcountTrend[] = [
                'month' => Carbon::create($year, $m, 1)->format('M'),
                'hires' => $monthlyHires[$m] ?? 0,
                'exits' => $monthlyExits[$m] ?? 0,
            ];
        }

        // Department distribution
        $deptDistribution = Employee::whereNull('active_until')
            ->when($companyFilter, fn($q) => $q->where('company', $companyFilter))
            ->selectRaw("COALESCE(NULLIF(TRIM(department),''), 'Unspecified') as dept, COUNT(*) as total")
            ->groupBy('dept')->orderByDesc('total')->get();

        // Company distribution
        $companyDistribution = Employee::whereNull('active_until')
            ->selectRaw("COALESCE(NULLIF(TRIM(company),''), 'Unspecified') as comp, COUNT(*) as total")
            ->groupBy('comp')->orderByDesc('total')->get();

        // Employment type breakdown
        $empTypeBreakdown = Employee::whereNull('active_until')
            ->when($companyFilter, fn($q) => $q->where('company', $companyFilter))
            ->selectRaw("COALESCE(employment_type, 'unspecified') as etype, COUNT(*) as total")
            ->groupBy('etype')->get();

        // Gender distribution
        $genderDistribution = Employee::whereNull('active_until')
            ->when($companyFilter, fn($q) => $q->where('company', $companyFilter))
            ->selectRaw("COALESCE(sex, 'unspecified') as gender, COUNT(*) as total")
            ->groupBy('gender')->get();

        // Tenure distribution (years)
        $tenureBuckets = ['< 1 year' => 0, '1-2 years' => 0, '2-5 years' => 0, '5-10 years' => 0, '10+ years' => 0];
        Employee::whereNull('active_until')
            ->when($companyFilter, fn($q) => $q->where('company', $companyFilter))
            ->whereNotNull('start_date')->chunk(200, function ($employees) use (&$tenureBuckets, $now) {
                foreach ($employees as $emp) {
                    $years = Carbon::parse($emp->start_date)->diffInYears($now);
                    if ($years < 1) $tenureBuckets['< 1 year']++;
                    elseif ($years < 2) $tenureBuckets['1-2 years']++;
                    elseif ($years < 5) $tenureBuckets['2-5 years']++;
                    elseif ($years < 10) $tenureBuckets['5-10 years']++;
                    else $tenureBuckets['10+ years']++;
                }
            });

        // ── Financial KPIs ─────────────────────────────────────────────
        $payrollStats = DB::table('pay_runs')
            ->where('year', $year)
            ->whereIn('status', ['approved', 'paid'])
            ->when($companyFilter, fn($q) => $q->where('company', $companyFilter))
            ->selectRaw('SUM(total_gross) as gross, SUM(total_deductions) as deductions, SUM(total_net) as net, SUM(total_employer_cost) as employer_cost, COUNT(*) as run_count')
            ->first();

        $monthlyPayroll = DB::table('pay_runs')
            ->where('year', $year)
            ->whereIn('status', ['approved', 'paid'])
            ->when($companyFilter, fn($q) => $q->where('company', $companyFilter))
            ->selectRaw('month as m, SUM(total_gross) as gross, SUM(total_net) as net, SUM(total_employer_cost) as employer_cost')
            ->groupBy('month')->pluck('gross', 'm')->toArray();

        $payrollTrend = [];
        for ($m = 1; $m <= 12; $m++) {
            $payrollTrend[] = [
                'month' => Carbon::create($year, $m, 1)->format('M'),
                'amount' => round((float)($monthlyPayroll[$m] ?? 0), 2),
            ];
        }

        // Statutory contributions summary
        $statutoryTotals = DB::table('payslips')
            ->join('pay_runs', 'payslips.pay_run_id', '=', 'pay_runs.id')
            ->where('pay_runs.year', $year)
            ->whereIn('pay_runs.status', ['approved', 'paid'])
            ->when($companyFilter, fn($q) => $q->where('pay_runs.company', $companyFilter))
            ->selectRaw('
                SUM(epf_employee) as epf_ee, SUM(epf_employer) as epf_er,
                SUM(socso_employee) as socso_ee, SUM(socso_employer) as socso_er,
                SUM(eis_employee) as eis_ee, SUM(eis_employer) as eis_er,
                SUM(pcb_amount) as pcb, SUM(hrdf_amount) as hrdf
            ')->first();

        // Average salary
        $avgSalary = DB::table('employee_salaries')
            ->where('is_active', true)
            ->when($companyFilter, fn($q) => $q->join('employees', 'employee_salaries.employee_id', '=', 'employees.id')
                ->where('employees.company', $companyFilter))
            ->avg('basic_salary');

        // ── Expense Claims KPIs ────────────────────────────────────────
        $claimsStats = DB::table('expense_claims')
            ->where('expense_claims.year', $year)
            ->when($companyFilter, fn($q) => $q->join('employees', 'expense_claims.employee_id', '=', 'employees.id')
                ->where('employees.company', $companyFilter))
            ->selectRaw("
                COUNT(*) as total_claims,
                SUM(CASE WHEN expense_claims.status = 'hr_approved' OR expense_claims.status = 'paid' THEN total_with_gst ELSE 0 END) as approved_amount,
                SUM(CASE WHEN expense_claims.status = 'submitted' OR expense_claims.status = 'manager_approved' THEN total_with_gst ELSE 0 END) as pending_amount,
                SUM(CASE WHEN expense_claims.status = 'hr_rejected' OR expense_claims.status = 'manager_rejected' THEN total_with_gst ELSE 0 END) as rejected_amount
            ")->first();

        $claimsByCategory = DB::table('expense_claim_items')
            ->join('expense_claims', 'expense_claim_items.expense_claim_id', '=', 'expense_claims.id')
            ->join('expense_categories', 'expense_claim_items.expense_category_id', '=', 'expense_categories.id')
            ->where('expense_claims.year', $year)
            ->whereIn('expense_claims.status', ['hr_approved', 'paid'])
            ->selectRaw('expense_categories.name as category, SUM(expense_claim_items.total_with_gst) as total')
            ->groupBy('expense_categories.name')
            ->orderByDesc('total')
            ->get();

        // ── Leave KPIs ─────────────────────────────────────────────────
        $leaveStats = DB::table('leave_applications')
            ->whereYear('leave_applications.start_date', $year)
            ->when($companyFilter, fn($q) => $q->join('employees', 'leave_applications.employee_id', '=', 'employees.id')
                ->where('employees.company', $companyFilter))
            ->selectRaw("
                COUNT(*) as total_applications,
                SUM(CASE WHEN leave_applications.status = 'approved' THEN 1 ELSE 0 END) as approved,
                SUM(CASE WHEN leave_applications.status = 'approved' THEN leave_applications.total_days ELSE 0 END) as total_days_taken,
                SUM(CASE WHEN leave_applications.status = 'pending' THEN 1 ELSE 0 END) as pending
            ")->first();

        $leaveByType = DB::table('leave_applications')
            ->join('leave_types', 'leave_applications.leave_type_id', '=', 'leave_types.id')
            ->whereYear('leave_applications.start_date', $year)
            ->where('leave_applications.status', 'approved')
            ->selectRaw('leave_types.name as type_name, SUM(leave_applications.total_days) as total_days, COUNT(*) as count')
            ->groupBy('leave_types.name')
            ->orderByDesc('total_days')
            ->get();

        // ── Attendance KPIs ────────────────────────────────────────────
        $attendanceStats = DB::table('attendance_records')
            ->whereYear('attendance_records.date', $year)
            ->when($companyFilter, fn($q) => $q->join('employees', 'attendance_records.employee_id', '=', 'employees.id')
                ->where('employees.company', $companyFilter))
            ->selectRaw("
                COUNT(*) as total_records,
                SUM(CASE WHEN attendance_records.status = 'present' THEN 1 ELSE 0 END) as present,
                SUM(CASE WHEN attendance_records.status = 'late' THEN 1 ELSE 0 END) as late,
                SUM(CASE WHEN attendance_records.status = 'absent' THEN 1 ELSE 0 END) as absent,
                SUM(attendance_records.overtime_hours) as total_ot_hours
            ")->first();

        $attendanceRate = ($attendanceStats->total_records ?? 0) > 0
            ? round((($attendanceStats->present ?? 0) + ($attendanceStats->late ?? 0)) / $attendanceStats->total_records * 100, 1)
            : 0;

        // ── Asset KPIs ─────────────────────────────────────────────────
        $assetStats = [
            'total' => AssetInventory::count(),
            'available' => AssetInventory::where('status', 'available')->count(),
            'assigned' => AssetInventory::whereIn('status', ['assigned', 'unavailable'])->count(),
            'maintenance' => AssetInventory::where('status', 'under_maintenance')->count(),
            'disposed' => AssetInventory::where('status', 'disposed')->count(),
        ];

        $assetsByType = AssetInventory::selectRaw('asset_type, COUNT(*) as total')
            ->groupBy('asset_type')->orderByDesc('total')->get();

        $assetCostTotal = AssetInventory::where('ownership_type', 'company')->sum('purchase_cost');
        $rentalCostMonthly = AssetInventory::where('ownership_type', 'rental')->sum('rental_cost_per_month');

        // ── Onboarding Pipeline ────────────────────────────────────────
        $pipelineStats = [
            'pending' => Onboarding::where('status', 'pending')->count(),
            'active' => Onboarding::where('status', 'active')->count(),
            'completed' => Onboarding::where('status', 'completed')
                ->whereYear('created_at', $year)->count(),
        ];

        return view('reports.executive-dashboard', compact(
            'year', 'companies', 'companyFilter', 'now',
            'totalActive', 'totalNewHires', 'totalExits', 'turnoverRate',
            'headcountTrend', 'deptDistribution', 'companyDistribution',
            'empTypeBreakdown', 'genderDistribution', 'tenureBuckets',
            'payrollStats', 'payrollTrend', 'statutoryTotals', 'avgSalary',
            'claimsStats', 'claimsByCategory',
            'leaveStats', 'leaveByType',
            'attendanceStats', 'attendanceRate',
            'assetStats', 'assetsByType', 'assetCostTotal', 'rentalCostMonthly',
            'pipelineStats'
        ));
    }

    // ═══════════════════════════════════════════════════════════════════
    // WORKFORCE REPORT — detailed headcount, demographics, tenure
    // ═══════════════════════════════════════════════════════════════════
    public function workforceReport(Request $request)
    {
        $this->authorize();

        $now = Carbon::now();
        $year = (int) $request->input('year', $now->year);
        $companies = Company::orderBy('name')->pluck('name')->toArray();
        $companyFilter = $request->input('company');

        $baseQ = fn() => Employee::whereNull('active_until')
            ->when($companyFilter, fn($q) => $q->where('company', $companyFilter));

        $totalActive = $baseQ()->count();

        // By company
        $byCompany = $baseQ()
            ->selectRaw("COALESCE(NULLIF(TRIM(company),''), 'Unspecified') as label, COUNT(*) as total")
            ->groupBy('label')->orderByDesc('total')->get();

        // By department
        $byDepartment = $baseQ()
            ->selectRaw("COALESCE(NULLIF(TRIM(department),''), 'Unspecified') as label, COUNT(*) as total")
            ->groupBy('label')->orderByDesc('total')->get();

        // By designation
        $byDesignation = $baseQ()
            ->selectRaw("COALESCE(NULLIF(TRIM(designation),''), 'Unspecified') as label, COUNT(*) as total")
            ->groupBy('label')->orderByDesc('total')->limit(15)->get();

        // Gender
        $byGender = $baseQ()
            ->selectRaw("COALESCE(sex, 'unspecified') as label, COUNT(*) as total")
            ->groupBy('label')->get();

        // Marital status
        $byMarital = $baseQ()
            ->selectRaw("COALESCE(marital_status, 'unspecified') as label, COUNT(*) as total")
            ->groupBy('label')->get();

        // Employment type
        $byEmpType = $baseQ()
            ->selectRaw("COALESCE(employment_type, 'unspecified') as label, COUNT(*) as total")
            ->groupBy('label')->get();

        // Age distribution
        $ageBuckets = ['18-25' => 0, '26-30' => 0, '31-35' => 0, '36-40' => 0, '41-50' => 0, '51-60' => 0, '60+' => 0];
        $baseQ()->whereNotNull('date_of_birth')->chunk(200, function ($employees) use (&$ageBuckets, $now) {
            foreach ($employees as $emp) {
                $age = Carbon::parse($emp->date_of_birth)->age;
                if ($age <= 25) $ageBuckets['18-25']++;
                elseif ($age <= 30) $ageBuckets['26-30']++;
                elseif ($age <= 35) $ageBuckets['31-35']++;
                elseif ($age <= 40) $ageBuckets['36-40']++;
                elseif ($age <= 50) $ageBuckets['41-50']++;
                elseif ($age <= 60) $ageBuckets['51-60']++;
                else $ageBuckets['60+']++;
            }
        });

        // Tenure distribution
        $tenureBuckets = ['< 1 year' => 0, '1-2 years' => 0, '2-5 years' => 0, '5-10 years' => 0, '10+ years' => 0];
        $baseQ()->whereNotNull('start_date')->chunk(200, function ($employees) use (&$tenureBuckets, $now) {
            foreach ($employees as $emp) {
                $years = Carbon::parse($emp->start_date)->diffInYears($now);
                if ($years < 1) $tenureBuckets['< 1 year']++;
                elseif ($years < 2) $tenureBuckets['1-2 years']++;
                elseif ($years < 5) $tenureBuckets['2-5 years']++;
                elseif ($years < 10) $tenureBuckets['5-10 years']++;
                else $tenureBuckets['10+ years']++;
            }
        });

        // Monthly hires & exits for the year
        $monthlyHires = WorkDetail::selectRaw('MONTH(start_date) as m, COUNT(*) as total')
            ->whereYear('start_date', $year)
            ->when($companyFilter, fn($q) => $q->where('company', $companyFilter))
            ->groupByRaw('MONTH(start_date)')->pluck('total', 'm')->toArray();

        $monthlyExits = Offboarding::selectRaw('MONTH(exit_date) as m, COUNT(*) as total')
            ->whereNotNull('exit_date')->whereYear('exit_date', $year)
            ->when($companyFilter, fn($q) => $q->where('company', $companyFilter))
            ->groupByRaw('MONTH(exit_date)')->pluck('total', 'm')->toArray();

        $hiresExitsTrend = [];
        for ($m = 1; $m <= 12; $m++) {
            $hiresExitsTrend[] = [
                'month' => Carbon::create($year, $m, 1)->format('M'),
                'hires' => $monthlyHires[$m] ?? 0,
                'exits' => $monthlyExits[$m] ?? 0,
            ];
        }

        // Top resignation reasons
        $resignReasons = Offboarding::whereNotNull('exit_date')
            ->whereYear('exit_date', $year)
            ->whereNotNull('reason')
            ->when($companyFilter, fn($q) => $q->where('company', $companyFilter))
            ->selectRaw("reason as label, COUNT(*) as total")
            ->groupBy('label')->orderByDesc('total')->limit(10)->get();

        return view('reports.workforce', compact(
            'year', 'companies', 'companyFilter', 'totalActive',
            'byCompany', 'byDepartment', 'byDesignation',
            'byGender', 'byMarital', 'byEmpType',
            'ageBuckets', 'tenureBuckets', 'hiresExitsTrend', 'resignReasons'
        ));
    }

    // ═══════════════════════════════════════════════════════════════════
    // FINANCIAL REPORT — payroll, statutory, claims
    // ═══════════════════════════════════════════════════════════════════
    public function financialReport(Request $request)
    {
        $this->authorize();

        $now = Carbon::now();
        $year = (int) $request->input('year', $now->year);
        $companies = Company::orderBy('name')->pluck('name')->toArray();
        $companyFilter = $request->input('company');

        // Monthly payroll breakdown
        $monthlyPayroll = DB::table('pay_runs')
            ->where('year', $year)
            ->whereIn('status', ['approved', 'paid'])
            ->when($companyFilter, fn($q) => $q->where('company', $companyFilter))
            ->selectRaw('month as m, SUM(total_gross) as gross, SUM(total_deductions) as deductions, SUM(total_net) as net, SUM(total_employer_cost) as employer_cost')
            ->groupBy('month')->orderBy('month')->get()->keyBy('m');

        $payrollTrend = [];
        $ytdGross = 0; $ytdNet = 0; $ytdEmployerCost = 0;
        for ($m = 1; $m <= 12; $m++) {
            $row = $monthlyPayroll->get($m);
            $gross = round((float)($row->gross ?? 0), 2);
            $net = round((float)($row->net ?? 0), 2);
            $ec = round((float)($row->employer_cost ?? 0), 2);
            $ytdGross += $gross;
            $ytdNet += $net;
            $ytdEmployerCost += $ec;
            $payrollTrend[] = [
                'month' => Carbon::create($year, $m, 1)->format('M'),
                'gross' => $gross,
                'net' => $net,
                'employer_cost' => $ec,
            ];
        }

        // Statutory contributions per month
        $monthlyStatutory = DB::table('payslips')
            ->join('pay_runs', 'payslips.pay_run_id', '=', 'pay_runs.id')
            ->where('pay_runs.year', $year)
            ->whereIn('pay_runs.status', ['approved', 'paid'])
            ->when($companyFilter, fn($q) => $q->where('pay_runs.company', $companyFilter))
            ->selectRaw('pay_runs.month as m,
                SUM(epf_employee + epf_employer) as epf_total,
                SUM(socso_employee + socso_employer) as socso_total,
                SUM(eis_employee + eis_employer) as eis_total,
                SUM(pcb_amount) as pcb_total,
                SUM(hrdf_amount) as hrdf_total')
            ->groupBy('pay_runs.month')->orderBy('pay_runs.month')->get()->keyBy('m');

        $statutoryTrend = [];
        for ($m = 1; $m <= 12; $m++) {
            $row = $monthlyStatutory->get($m);
            $statutoryTrend[] = [
                'month' => Carbon::create($year, $m, 1)->format('M'),
                'epf' => round((float)($row->epf_total ?? 0), 2),
                'socso' => round((float)($row->socso_total ?? 0), 2),
                'eis' => round((float)($row->eis_total ?? 0), 2),
                'pcb' => round((float)($row->pcb_total ?? 0), 2),
                'hrdf' => round((float)($row->hrdf_total ?? 0), 2),
            ];
        }

        // Top earners (by basic salary)
        $topEarners = DB::table('employee_salaries')
            ->join('employees', 'employee_salaries.employee_id', '=', 'employees.id')
            ->where('employee_salaries.is_active', true)
            ->whereNull('employees.active_until')
            ->when($companyFilter, fn($q) => $q->where('employees.company', $companyFilter))
            ->select('employees.full_name', 'employees.designation', 'employees.department',
                     'employees.company', 'employee_salaries.basic_salary')
            ->orderByDesc('employee_salaries.basic_salary')
            ->limit(10)->get();

        // Salary distribution by department
        $salaryByDept = DB::table('employee_salaries')
            ->join('employees', 'employee_salaries.employee_id', '=', 'employees.id')
            ->where('employee_salaries.is_active', true)
            ->whereNull('employees.active_until')
            ->when($companyFilter, fn($q) => $q->where('employees.company', $companyFilter))
            ->selectRaw("COALESCE(NULLIF(TRIM(employees.department),''), 'Unspecified') as dept,
                         COUNT(*) as headcount,
                         AVG(employee_salaries.basic_salary) as avg_salary,
                         MIN(employee_salaries.basic_salary) as min_salary,
                         MAX(employee_salaries.basic_salary) as max_salary,
                         SUM(employee_salaries.basic_salary) as total_salary")
            ->groupBy('dept')->orderByDesc('total_salary')->get();

        // Expense claims summary
        $claimsByMonth = DB::table('expense_claims')
            ->where('expense_claims.year', $year)
            ->whereIn('expense_claims.status', ['hr_approved', 'paid'])
            ->when($companyFilter, fn($q) => $q->join('employees', 'expense_claims.employee_id', '=', 'employees.id')
                ->where('employees.company', $companyFilter))
            ->selectRaw('expense_claims.month as m, SUM(expense_claims.total_with_gst) as total, COUNT(*) as count')
            ->groupBy('expense_claims.month')->orderBy('expense_claims.month')->pluck('total', 'm')->toArray();

        $claimsTrend = [];
        for ($m = 1; $m <= 12; $m++) {
            $claimsTrend[] = [
                'month' => Carbon::create($year, $m, 1)->format('M'),
                'amount' => round((float)($claimsByMonth[$m] ?? 0), 2),
            ];
        }

        $claimsByCategory = DB::table('expense_claim_items')
            ->join('expense_claims', 'expense_claim_items.expense_claim_id', '=', 'expense_claims.id')
            ->join('expense_categories', 'expense_claim_items.expense_category_id', '=', 'expense_categories.id')
            ->where('expense_claims.year', $year)
            ->whereIn('expense_claims.status', ['hr_approved', 'paid'])
            ->when($companyFilter, fn($q) => $q->join('employees', 'expense_claims.employee_id', '=', 'employees.id')
                ->where('employees.company', $companyFilter))
            ->selectRaw('expense_categories.name as category, SUM(expense_claim_items.total_with_gst) as total, COUNT(*) as count')
            ->groupBy('expense_categories.name')
            ->orderByDesc('total')->get();

        return view('reports.financial', compact(
            'year', 'companies', 'companyFilter',
            'payrollTrend', 'ytdGross', 'ytdNet', 'ytdEmployerCost',
            'statutoryTrend', 'topEarners', 'salaryByDept',
            'claimsTrend', 'claimsByCategory'
        ));
    }

    // ═══════════════════════════════════════════════════════════════════
    // LEAVE REPORT — utilization, patterns, balances
    // ═══════════════════════════════════════════════════════════════════
    public function leaveReport(Request $request)
    {
        $this->authorize();

        $now = Carbon::now();
        $year = (int) $request->input('year', $now->year);
        $companies = Company::orderBy('name')->pluck('name')->toArray();
        $companyFilter = $request->input('company');

        // Leave applications by month
        $monthlyLeave = DB::table('leave_applications')
            ->whereYear('leave_applications.start_date', $year)
            ->where('leave_applications.status', 'approved')
            ->when($companyFilter, fn($q) => $q->join('employees', 'leave_applications.employee_id', '=', 'employees.id')
                ->where('employees.company', $companyFilter))
            ->selectRaw('MONTH(leave_applications.start_date) as m, SUM(leave_applications.total_days) as total_days, COUNT(*) as count')
            ->groupByRaw('MONTH(leave_applications.start_date)')->pluck('total_days', 'm')->toArray();

        $leaveTrend = [];
        for ($m = 1; $m <= 12; $m++) {
            $leaveTrend[] = [
                'month' => Carbon::create($year, $m, 1)->format('M'),
                'days' => round((float)($monthlyLeave[$m] ?? 0), 1),
            ];
        }

        // Leave by type
        $byType = DB::table('leave_applications')
            ->join('leave_types', 'leave_applications.leave_type_id', '=', 'leave_types.id')
            ->whereYear('leave_applications.start_date', $year)
            ->where('leave_applications.status', 'approved')
            ->when($companyFilter, fn($q) => $q->join('employees', 'leave_applications.employee_id', '=', 'employees.id')
                ->where('employees.company', $companyFilter))
            ->selectRaw('leave_types.name as type_name, leave_types.code, SUM(leave_applications.total_days) as total_days, COUNT(*) as count')
            ->groupBy('leave_types.name', 'leave_types.code')
            ->orderByDesc('total_days')->get();

        // Leave by department
        $byDepartment = DB::table('leave_applications')
            ->join('employees', 'leave_applications.employee_id', '=', 'employees.id')
            ->whereYear('leave_applications.start_date', $year)
            ->where('leave_applications.status', 'approved')
            ->when($companyFilter, fn($q) => $q->where('employees.company', $companyFilter))
            ->selectRaw("COALESCE(NULLIF(TRIM(employees.department),''), 'Unspecified') as dept, SUM(leave_applications.total_days) as total_days, COUNT(*) as count")
            ->groupBy('dept')->orderByDesc('total_days')->get();

        // Top leave takers
        $topLeaveTakers = DB::table('leave_applications')
            ->join('employees', 'leave_applications.employee_id', '=', 'employees.id')
            ->whereYear('leave_applications.start_date', $year)
            ->where('leave_applications.status', 'approved')
            ->when($companyFilter, fn($q) => $q->where('employees.company', $companyFilter))
            ->selectRaw('employees.full_name, employees.department, employees.company, SUM(leave_applications.total_days) as total_days')
            ->groupBy('employees.id', 'employees.full_name', 'employees.department', 'employees.company')
            ->orderByDesc('total_days')->limit(15)->get();

        // Leave balance utilization (entitled vs taken)
        $balanceUtilization = DB::table('leave_balances')
            ->join('leave_types', 'leave_balances.leave_type_id', '=', 'leave_types.id')
            ->where('leave_balances.year', $year)
            ->when($companyFilter, fn($q) => $q->join('employees', 'leave_balances.employee_id', '=', 'employees.id')
                ->where('employees.company', $companyFilter))
            ->selectRaw('leave_types.name as type_name, SUM(leave_balances.entitled) as total_entitled, SUM(leave_balances.taken) as total_taken, SUM(leave_balances.carry_forward) as total_cf')
            ->groupBy('leave_types.name')
            ->orderByDesc('total_entitled')->get();

        return view('reports.leave', compact(
            'year', 'companies', 'companyFilter',
            'leaveTrend', 'byType', 'byDepartment', 'topLeaveTakers', 'balanceUtilization'
        ));
    }

    // ═══════════════════════════════════════════════════════════════════
    // ATTENDANCE REPORT — rates, overtime, patterns
    // ═══════════════════════════════════════════════════════════════════
    public function attendanceReport(Request $request)
    {
        $this->authorize();

        $now = Carbon::now();
        $year = (int) $request->input('year', $now->year);
        $companies = Company::orderBy('name')->pluck('name')->toArray();
        $companyFilter = $request->input('company');

        // Monthly attendance breakdown
        $monthlyAttendance = DB::table('attendance_records')
            ->whereYear('attendance_records.date', $year)
            ->when($companyFilter, fn($q) => $q->join('employees', 'attendance_records.employee_id', '=', 'employees.id')
                ->where('employees.company', $companyFilter))
            ->selectRaw("MONTH(attendance_records.date) as m,
                SUM(CASE WHEN attendance_records.status = 'present' THEN 1 ELSE 0 END) as present,
                SUM(CASE WHEN attendance_records.status = 'late' THEN 1 ELSE 0 END) as late,
                SUM(CASE WHEN attendance_records.status = 'absent' THEN 1 ELSE 0 END) as absent,
                SUM(CASE WHEN attendance_records.status = 'on_leave' THEN 1 ELSE 0 END) as on_leave,
                COUNT(*) as total,
                SUM(attendance_records.overtime_hours) as ot_hours")
            ->groupByRaw('MONTH(attendance_records.date)')->orderByRaw('MONTH(attendance_records.date)')->get()->keyBy('m');

        $attendanceTrend = [];
        for ($m = 1; $m <= 12; $m++) {
            $row = $monthlyAttendance->get($m);
            $total = (int)($row->total ?? 0);
            $present = (int)($row->present ?? 0);
            $late = (int)($row->late ?? 0);
            $attendanceTrend[] = [
                'month' => Carbon::create($year, $m, 1)->format('M'),
                'rate' => $total > 0 ? round(($present + $late) / $total * 100, 1) : 0,
                'late_rate' => $total > 0 ? round($late / $total * 100, 1) : 0,
                'absent' => (int)($row->absent ?? 0),
                'ot_hours' => round((float)($row->ot_hours ?? 0), 1),
            ];
        }

        // Attendance by department
        $byDepartment = DB::table('attendance_records')
            ->join('employees', 'attendance_records.employee_id', '=', 'employees.id')
            ->whereYear('attendance_records.date', $year)
            ->when($companyFilter, fn($q) => $q->where('employees.company', $companyFilter))
            ->selectRaw("COALESCE(NULLIF(TRIM(employees.department),''), 'Unspecified') as dept,
                COUNT(*) as total,
                SUM(CASE WHEN attendance_records.status = 'present' THEN 1 ELSE 0 END) as present,
                SUM(CASE WHEN attendance_records.status = 'late' THEN 1 ELSE 0 END) as late,
                SUM(CASE WHEN attendance_records.status = 'absent' THEN 1 ELSE 0 END) as absent")
            ->groupBy('dept')->orderByDesc('total')->get()->map(function ($row) {
                $row->rate = $row->total > 0 ? round(($row->present + $row->late) / $row->total * 100, 1) : 0;
                return $row;
            });

        // Overtime trends
        $overtimeByMonth = DB::table('overtime_requests')
            ->whereYear('overtime_requests.date', $year)
            ->where('overtime_requests.status', 'approved')
            ->when($companyFilter, fn($q) => $q->join('employees', 'overtime_requests.employee_id', '=', 'employees.id')
                ->where('employees.company', $companyFilter))
            ->selectRaw('MONTH(overtime_requests.date) as m, SUM(overtime_requests.hours) as total_hours, COUNT(*) as count')
            ->groupByRaw('MONTH(overtime_requests.date)')->pluck('total_hours', 'm')->toArray();

        $overtimeTrend = [];
        for ($m = 1; $m <= 12; $m++) {
            $overtimeTrend[] = [
                'month' => Carbon::create($year, $m, 1)->format('M'),
                'hours' => round((float)($overtimeByMonth[$m] ?? 0), 1),
            ];
        }

        // Top overtime employees
        $topOvertimeEmployees = DB::table('overtime_requests')
            ->join('employees', 'overtime_requests.employee_id', '=', 'employees.id')
            ->whereYear('overtime_requests.date', $year)
            ->where('overtime_requests.status', 'approved')
            ->when($companyFilter, fn($q) => $q->where('employees.company', $companyFilter))
            ->selectRaw('employees.full_name, employees.department, employees.company, SUM(overtime_requests.hours) as total_hours, COUNT(*) as count')
            ->groupBy('employees.id', 'employees.full_name', 'employees.department', 'employees.company')
            ->orderByDesc('total_hours')->limit(10)->get();

        return view('reports.attendance', compact(
            'year', 'companies', 'companyFilter',
            'attendanceTrend', 'byDepartment', 'overtimeTrend', 'topOvertimeEmployees'
        ));
    }

    // ═══════════════════════════════════════════════════════════════════
    // ASSET REPORT — portfolio, costs, utilization
    // ═══════════════════════════════════════════════════════════════════
    public function assetReport(Request $request)
    {
        $this->authorize();

        $companies = Company::orderBy('name')->pluck('name')->toArray();

        // Status overview
        $statusBreakdown = AssetInventory::selectRaw('status, COUNT(*) as total')
            ->groupBy('status')->orderByDesc('total')->get();

        // By type
        $byType = AssetInventory::selectRaw('asset_type, COUNT(*) as total, SUM(CASE WHEN status = "available" THEN 1 ELSE 0 END) as available, SUM(CASE WHEN status IN ("assigned","unavailable") THEN 1 ELSE 0 END) as assigned')
            ->groupBy('asset_type')->orderByDesc('total')->get();

        // Ownership breakdown
        $ownership = [
            'company_count' => AssetInventory::where('ownership_type', 'company')->count(),
            'rental_count' => AssetInventory::where('ownership_type', 'rental')->count(),
            'company_cost' => AssetInventory::where('ownership_type', 'company')->sum('purchase_cost'),
            'rental_monthly' => AssetInventory::where('ownership_type', 'rental')->sum('rental_cost_per_month'),
        ];

        // By company (for company-owned)
        $byCompanyOwned = AssetInventory::where('ownership_type', 'company')
            ->selectRaw("COALESCE(NULLIF(TRIM(company_name),''), 'Unspecified') as label, COUNT(*) as total, SUM(purchase_cost) as cost")
            ->groupBy('label')->orderByDesc('total')->get();

        // By rental vendor
        $byRentalVendor = AssetInventory::where('ownership_type', 'rental')
            ->selectRaw("COALESCE(NULLIF(TRIM(rental_vendor),''), 'Unspecified') as label, COUNT(*) as total, SUM(rental_cost_per_month) as monthly_cost")
            ->groupBy('label')->orderByDesc('total')->get();

        // Condition breakdown
        $conditionBreakdown = AssetInventory::selectRaw("COALESCE(asset_condition, 'unknown') as cond, COUNT(*) as total")
            ->groupBy('cond')->orderByDesc('total')->get();

        // Warranty expiring soon (next 90 days)
        $warrantyExpiring = AssetInventory::whereNotNull('warranty_expiry_date')
            ->where('warranty_expiry_date', '>=', now())
            ->where('warranty_expiry_date', '<=', now()->addDays(90))
            ->orderBy('warranty_expiry_date')
            ->get(['asset_tag', 'asset_type', 'brand', 'model', 'warranty_expiry_date', 'status']);

        // Rental contracts expiring soon (next 90 days)
        $rentalExpiring = AssetInventory::where('ownership_type', 'rental')
            ->whereNotNull('rental_end_date')
            ->where('rental_end_date', '>=', now())
            ->where('rental_end_date', '<=', now()->addDays(90))
            ->orderBy('rental_end_date')
            ->get(['asset_tag', 'asset_type', 'brand', 'model', 'rental_vendor', 'rental_end_date', 'rental_cost_per_month']);

        return view('reports.assets', compact(
            'companies',
            'statusBreakdown', 'byType', 'ownership',
            'byCompanyOwned', 'byRentalVendor', 'conditionBreakdown',
            'warrantyExpiring', 'rentalExpiring'
        ));
    }
}
