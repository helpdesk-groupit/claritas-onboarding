<?php

namespace App\Http\Controllers;

use App\Models\AssetDecommissionBatch;
use App\Models\AssetInventory;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Offboarding;
use App\Models\Onboarding;
use App\Models\WorkDetail;
use App\Services\DecommissionReportRenderer;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ReportController extends Controller
{
    /** Only superadmin + hr_manager can access reports */
    private function authorize(): void
    {
        $user = Auth::user();
        if (! $user->isSuperadmin() && ! $user->isHrManager() && ! $user->isSystemAdmin()) {
            abort(403);
        }
    }

    /**
     * Build a company name resolver that normalizes variants to registered canonical names.
     * E.g. "Enlinea Sdn Bhd" and "Enlinea Sdn. Bhd." both resolve to the registered form.
     */
    private function companyResolver(): \Closure
    {
        $normaliseCo = fn (string $s) => strtolower(str_replace(['.', ','], '', preg_replace('/\s+/', ' ', trim($s))));
        $canonMap = [];
        foreach (Company::orderBy('name')->pluck('name') as $name) {
            $canonMap[$normaliseCo($name)] = $name;
        }

        return fn (?string $raw) => $canonMap[$normaliseCo(trim($raw ?? ''))] ?? (trim($raw ?? '') ?: 'Unspecified');
    }

    /**
     * Normalize and merge a collection of rows with a company-like field.
     * Returns a collection of objects with label + total (+ any extra numeric fields summed).
     */
    private function mergeByCompany($rows, string $field = 'label', array $sumFields = ['total']): \Illuminate\Support\Collection
    {
        $resolveCo = $this->companyResolver();
        $merged = [];
        foreach ($rows as $row) {
            $key = $resolveCo($row->$field);
            if (! isset($merged[$key])) {
                $merged[$key] = (object) [$field => $key];
                foreach ($sumFields as $f) {
                    $merged[$key]->$f = 0;
                }
            }
            foreach ($sumFields as $f) {
                $merged[$key]->$f += ($row->$f ?? 0);
            }
        }

        return collect($merged)->sortByDesc('total')->values();
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
        if ($companyFilter) {
            $activeQ = $activeQ->where('company', $companyFilter);
        }
        $totalActive = $activeQ->count();

        $newHiresYear = WorkDetail::whereYear('start_date', $year);
        if ($companyFilter) {
            $newHiresYear = $newHiresYear->where('company', $companyFilter);
        }
        $totalNewHires = $newHiresYear->count();

        $exitsYear = Offboarding::whereNotNull('exit_date')->whereYear('exit_date', $year);
        if ($companyFilter) {
            $exitsYear = $exitsYear->where('company', $companyFilter);
        }
        $totalExits = $exitsYear->count();

        $turnoverRate = $totalActive > 0 ? round(($totalExits / $totalActive) * 100, 1) : 0;

        // Monthly headcount trend (new hires vs exits per month)
        $monthlyHires = WorkDetail::selectRaw('MONTH(start_date) as m, COUNT(*) as total')
            ->whereYear('start_date', $year)
            ->when($companyFilter, fn ($q) => $q->where('company', $companyFilter))
            ->groupByRaw('MONTH(start_date)')->pluck('total', 'm')->toArray();

        $monthlyExits = Offboarding::selectRaw('MONTH(exit_date) as m, COUNT(*) as total')
            ->whereNotNull('exit_date')->whereYear('exit_date', $year)
            ->when($companyFilter, fn ($q) => $q->where('company', $companyFilter))
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
            ->when($companyFilter, fn ($q) => $q->where('company', $companyFilter))
            ->selectRaw("COALESCE(NULLIF(TRIM(department),''), 'Unspecified') as dept, COUNT(*) as total")
            ->groupBy('dept')->orderByDesc('total')->get();

        // Company distribution (normalized)
        $companyDistribution = $this->mergeByCompany(
            Employee::whereNull('active_until')
                ->selectRaw("COALESCE(NULLIF(TRIM(company),''), 'Unspecified') as label, COUNT(*) as total")
                ->groupBy('label')->get(),
            'label', ['total']
        );

        // Employment type breakdown
        $empTypeBreakdown = Employee::whereNull('active_until')
            ->when($companyFilter, fn ($q) => $q->where('company', $companyFilter))
            ->selectRaw("COALESCE(employment_type, 'unspecified') as etype, COUNT(*) as total")
            ->groupBy('etype')->get();

        // Gender distribution
        $genderDistribution = Employee::whereNull('active_until')
            ->when($companyFilter, fn ($q) => $q->where('company', $companyFilter))
            ->selectRaw("COALESCE(sex, 'unspecified') as gender, COUNT(*) as total")
            ->groupBy('gender')->get();

        // Tenure distribution (years)
        $tenureBuckets = ['< 1 year' => 0, '1-2 years' => 0, '2-5 years' => 0, '5-10 years' => 0, '10+ years' => 0];
        Employee::whereNull('active_until')
            ->when($companyFilter, fn ($q) => $q->where('company', $companyFilter))
            ->whereNotNull('start_date')->chunk(200, function ($employees) use (&$tenureBuckets, $now) {
                foreach ($employees as $emp) {
                    $years = Carbon::parse($emp->start_date)->diffInYears($now);
                    if ($years < 1) {
                        $tenureBuckets['< 1 year']++;
                    } elseif ($years < 2) {
                        $tenureBuckets['1-2 years']++;
                    } elseif ($years < 5) {
                        $tenureBuckets['2-5 years']++;
                    } elseif ($years < 10) {
                        $tenureBuckets['5-10 years']++;
                    } else {
                        $tenureBuckets['10+ years']++;
                    }
                }
            });

        // ── Financial KPIs ─────────────────────────────────────────────
        $payrollStats = DB::table('pay_runs')
            ->where('year', $year)
            ->whereIn('status', ['approved', 'paid'])
            ->when($companyFilter, fn ($q) => $q->where('company', $companyFilter))
            ->selectRaw('SUM(total_gross) as gross, SUM(total_deductions) as deductions, SUM(total_net) as net, SUM(total_employer_cost) as employer_cost, COUNT(*) as run_count')
            ->first();

        $monthlyPayroll = DB::table('pay_runs')
            ->where('year', $year)
            ->whereIn('status', ['approved', 'paid'])
            ->when($companyFilter, fn ($q) => $q->where('company', $companyFilter))
            ->selectRaw('month as m, SUM(total_gross) as gross, SUM(total_net) as net, SUM(total_employer_cost) as employer_cost')
            ->groupBy('month')->pluck('gross', 'm')->toArray();

        $payrollTrend = [];
        for ($m = 1; $m <= 12; $m++) {
            $payrollTrend[] = [
                'month' => Carbon::create($year, $m, 1)->format('M'),
                'amount' => round((float) ($monthlyPayroll[$m] ?? 0), 2),
            ];
        }

        // Statutory contributions summary
        $statutoryTotals = DB::table('payslips')
            ->join('pay_runs', 'payslips.pay_run_id', '=', 'pay_runs.id')
            ->where('pay_runs.year', $year)
            ->whereIn('pay_runs.status', ['approved', 'paid'])
            ->when($companyFilter, fn ($q) => $q->where('pay_runs.company', $companyFilter))
            ->selectRaw('
                SUM(epf_employee) as epf_ee, SUM(epf_employer) as epf_er,
                SUM(socso_employee) as socso_ee, SUM(socso_employer) as socso_er,
                SUM(eis_employee) as eis_ee, SUM(eis_employer) as eis_er,
                SUM(pcb_amount) as pcb, SUM(hrdf_amount) as hrdf
            ')->first();

        // Average salary
        $avgSalary = DB::table('employee_salaries')
            ->where('is_active', true)
            ->when($companyFilter, fn ($q) => $q->join('employees', 'employee_salaries.employee_id', '=', 'employees.id')
                ->where('employees.company', $companyFilter))
            ->avg('basic_salary');

        // ── Expense Claims KPIs ────────────────────────────────────────
        $claimsStats = DB::table('expense_claims')
            ->where('expense_claims.year', $year)
            ->when($companyFilter, fn ($q) => $q->join('employees', 'expense_claims.employee_id', '=', 'employees.id')
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
            ->when($companyFilter, fn ($q) => $q->join('employees', 'leave_applications.employee_id', '=', 'employees.id')
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
            ->when($companyFilter, fn ($q) => $q->join('employees', 'attendance_records.employee_id', '=', 'employees.id')
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

        $baseQ = fn () => Employee::whereNull('active_until')
            ->when($companyFilter, fn ($q) => $q->where('company', $companyFilter));

        $totalActive = $baseQ()->count();

        // By company (normalized)
        $byCompany = $this->mergeByCompany(
            $baseQ()->selectRaw("COALESCE(NULLIF(TRIM(company),''), 'Unspecified') as label, COUNT(*) as total")
                ->groupBy('label')->get(),
            'label', ['total']
        );

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
        $baseQ()->whereNotNull('date_of_birth')->chunk(200, function ($employees) use (&$ageBuckets) {
            foreach ($employees as $emp) {
                $age = Carbon::parse($emp->date_of_birth)->age;
                if ($age <= 25) {
                    $ageBuckets['18-25']++;
                } elseif ($age <= 30) {
                    $ageBuckets['26-30']++;
                } elseif ($age <= 35) {
                    $ageBuckets['31-35']++;
                } elseif ($age <= 40) {
                    $ageBuckets['36-40']++;
                } elseif ($age <= 50) {
                    $ageBuckets['41-50']++;
                } elseif ($age <= 60) {
                    $ageBuckets['51-60']++;
                } else {
                    $ageBuckets['60+']++;
                }
            }
        });

        // Tenure distribution
        $tenureBuckets = ['< 1 year' => 0, '1-2 years' => 0, '2-5 years' => 0, '5-10 years' => 0, '10+ years' => 0];
        $baseQ()->whereNotNull('start_date')->chunk(200, function ($employees) use (&$tenureBuckets, $now) {
            foreach ($employees as $emp) {
                $years = Carbon::parse($emp->start_date)->diffInYears($now);
                if ($years < 1) {
                    $tenureBuckets['< 1 year']++;
                } elseif ($years < 2) {
                    $tenureBuckets['1-2 years']++;
                } elseif ($years < 5) {
                    $tenureBuckets['2-5 years']++;
                } elseif ($years < 10) {
                    $tenureBuckets['5-10 years']++;
                } else {
                    $tenureBuckets['10+ years']++;
                }
            }
        });

        // Monthly hires & exits for the year
        $monthlyHires = WorkDetail::selectRaw('MONTH(start_date) as m, COUNT(*) as total')
            ->whereYear('start_date', $year)
            ->when($companyFilter, fn ($q) => $q->where('company', $companyFilter))
            ->groupByRaw('MONTH(start_date)')->pluck('total', 'm')->toArray();

        $monthlyExits = Offboarding::selectRaw('MONTH(exit_date) as m, COUNT(*) as total')
            ->whereNotNull('exit_date')->whereYear('exit_date', $year)
            ->when($companyFilter, fn ($q) => $q->where('company', $companyFilter))
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
            ->when($companyFilter, fn ($q) => $q->where('company', $companyFilter))
            ->selectRaw('reason as label, COUNT(*) as total')
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
            ->when($companyFilter, fn ($q) => $q->where('company', $companyFilter))
            ->selectRaw('month as m, SUM(total_gross) as gross, SUM(total_deductions) as deductions, SUM(total_net) as net, SUM(total_employer_cost) as employer_cost')
            ->groupBy('month')->orderBy('month')->get()->keyBy('m');

        $payrollTrend = [];
        $ytdGross = 0;
        $ytdNet = 0;
        $ytdEmployerCost = 0;
        for ($m = 1; $m <= 12; $m++) {
            $row = $monthlyPayroll->get($m);
            $gross = round((float) ($row->gross ?? 0), 2);
            $net = round((float) ($row->net ?? 0), 2);
            $ec = round((float) ($row->employer_cost ?? 0), 2);
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
            ->when($companyFilter, fn ($q) => $q->where('pay_runs.company', $companyFilter))
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
                'epf' => round((float) ($row->epf_total ?? 0), 2),
                'socso' => round((float) ($row->socso_total ?? 0), 2),
                'eis' => round((float) ($row->eis_total ?? 0), 2),
                'pcb' => round((float) ($row->pcb_total ?? 0), 2),
                'hrdf' => round((float) ($row->hrdf_total ?? 0), 2),
            ];
        }

        // Top earners (by basic salary)
        $topEarners = DB::table('employee_salaries')
            ->join('employees', 'employee_salaries.employee_id', '=', 'employees.id')
            ->where('employee_salaries.is_active', true)
            ->whereNull('employees.active_until')
            ->when($companyFilter, fn ($q) => $q->where('employees.company', $companyFilter))
            ->select('employees.full_name', 'employees.designation', 'employees.department',
                'employees.company', 'employee_salaries.basic_salary')
            ->orderByDesc('employee_salaries.basic_salary')
            ->limit(10)->get();

        // Salary distribution by department
        $salaryByDept = DB::table('employee_salaries')
            ->join('employees', 'employee_salaries.employee_id', '=', 'employees.id')
            ->where('employee_salaries.is_active', true)
            ->whereNull('employees.active_until')
            ->when($companyFilter, fn ($q) => $q->where('employees.company', $companyFilter))
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
            ->when($companyFilter, fn ($q) => $q->join('employees', 'expense_claims.employee_id', '=', 'employees.id')
                ->where('employees.company', $companyFilter))
            ->selectRaw('expense_claims.month as m, SUM(expense_claims.total_with_gst) as total, COUNT(*) as count')
            ->groupBy('expense_claims.month')->orderBy('expense_claims.month')->pluck('total', 'm')->toArray();

        $claimsTrend = [];
        for ($m = 1; $m <= 12; $m++) {
            $claimsTrend[] = [
                'month' => Carbon::create($year, $m, 1)->format('M'),
                'amount' => round((float) ($claimsByMonth[$m] ?? 0), 2),
            ];
        }

        $claimsByCategory = DB::table('expense_claim_items')
            ->join('expense_claims', 'expense_claim_items.expense_claim_id', '=', 'expense_claims.id')
            ->join('expense_categories', 'expense_claim_items.expense_category_id', '=', 'expense_categories.id')
            ->where('expense_claims.year', $year)
            ->whereIn('expense_claims.status', ['hr_approved', 'paid'])
            ->when($companyFilter, fn ($q) => $q->join('employees', 'expense_claims.employee_id', '=', 'employees.id')
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
            ->when($companyFilter, fn ($q) => $q->join('employees', 'leave_applications.employee_id', '=', 'employees.id')
                ->where('employees.company', $companyFilter))
            ->selectRaw('MONTH(leave_applications.start_date) as m, SUM(leave_applications.total_days) as total_days, COUNT(*) as count')
            ->groupByRaw('MONTH(leave_applications.start_date)')->pluck('total_days', 'm')->toArray();

        $leaveTrend = [];
        for ($m = 1; $m <= 12; $m++) {
            $leaveTrend[] = [
                'month' => Carbon::create($year, $m, 1)->format('M'),
                'days' => round((float) ($monthlyLeave[$m] ?? 0), 1),
            ];
        }

        // Leave by type
        $byType = DB::table('leave_applications')
            ->join('leave_types', 'leave_applications.leave_type_id', '=', 'leave_types.id')
            ->whereYear('leave_applications.start_date', $year)
            ->where('leave_applications.status', 'approved')
            ->when($companyFilter, fn ($q) => $q->join('employees', 'leave_applications.employee_id', '=', 'employees.id')
                ->where('employees.company', $companyFilter))
            ->selectRaw('leave_types.name as type_name, leave_types.code, SUM(leave_applications.total_days) as total_days, COUNT(*) as count')
            ->groupBy('leave_types.name', 'leave_types.code')
            ->orderByDesc('total_days')->get();

        // Leave by department
        $byDepartment = DB::table('leave_applications')
            ->join('employees', 'leave_applications.employee_id', '=', 'employees.id')
            ->whereYear('leave_applications.start_date', $year)
            ->where('leave_applications.status', 'approved')
            ->when($companyFilter, fn ($q) => $q->where('employees.company', $companyFilter))
            ->selectRaw("COALESCE(NULLIF(TRIM(employees.department),''), 'Unspecified') as dept, SUM(leave_applications.total_days) as total_days, COUNT(*) as count")
            ->groupBy('dept')->orderByDesc('total_days')->get();

        // Top leave takers
        $topLeaveTakers = DB::table('leave_applications')
            ->join('employees', 'leave_applications.employee_id', '=', 'employees.id')
            ->whereYear('leave_applications.start_date', $year)
            ->where('leave_applications.status', 'approved')
            ->when($companyFilter, fn ($q) => $q->where('employees.company', $companyFilter))
            ->selectRaw('employees.full_name, employees.department, employees.company, SUM(leave_applications.total_days) as total_days')
            ->groupBy('employees.id', 'employees.full_name', 'employees.department', 'employees.company')
            ->orderByDesc('total_days')->limit(15)->get();

        // Leave balance utilization (entitled vs taken)
        $balanceUtilization = DB::table('leave_balances')
            ->join('leave_types', 'leave_balances.leave_type_id', '=', 'leave_types.id')
            ->where('leave_balances.year', $year)
            ->when($companyFilter, fn ($q) => $q->join('employees', 'leave_balances.employee_id', '=', 'employees.id')
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
            ->when($companyFilter, fn ($q) => $q->join('employees', 'attendance_records.employee_id', '=', 'employees.id')
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
            $total = (int) ($row->total ?? 0);
            $present = (int) ($row->present ?? 0);
            $late = (int) ($row->late ?? 0);
            $attendanceTrend[] = [
                'month' => Carbon::create($year, $m, 1)->format('M'),
                'rate' => $total > 0 ? round(($present + $late) / $total * 100, 1) : 0,
                'late_rate' => $total > 0 ? round($late / $total * 100, 1) : 0,
                'absent' => (int) ($row->absent ?? 0),
                'ot_hours' => round((float) ($row->ot_hours ?? 0), 1),
            ];
        }

        // Attendance by department
        $byDepartment = DB::table('attendance_records')
            ->join('employees', 'attendance_records.employee_id', '=', 'employees.id')
            ->whereYear('attendance_records.date', $year)
            ->when($companyFilter, fn ($q) => $q->where('employees.company', $companyFilter))
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
            ->when($companyFilter, fn ($q) => $q->join('employees', 'overtime_requests.employee_id', '=', 'employees.id')
                ->where('employees.company', $companyFilter))
            ->selectRaw('MONTH(overtime_requests.date) as m, SUM(overtime_requests.hours) as total_hours, COUNT(*) as count')
            ->groupByRaw('MONTH(overtime_requests.date)')->pluck('total_hours', 'm')->toArray();

        $overtimeTrend = [];
        for ($m = 1; $m <= 12; $m++) {
            $overtimeTrend[] = [
                'month' => Carbon::create($year, $m, 1)->format('M'),
                'hours' => round((float) ($overtimeByMonth[$m] ?? 0), 1),
            ];
        }

        // Top overtime employees
        $topOvertimeEmployees = DB::table('overtime_requests')
            ->join('employees', 'overtime_requests.employee_id', '=', 'employees.id')
            ->whereYear('overtime_requests.date', $year)
            ->where('overtime_requests.status', 'approved')
            ->when($companyFilter, fn ($q) => $q->where('employees.company', $companyFilter))
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
        $resolveCo = $this->companyResolver();

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

        // By company (for company-owned) — normalized
        $rawByCompany = AssetInventory::where('ownership_type', 'company')
            ->selectRaw("COALESCE(NULLIF(TRIM(company_name),''), 'Unspecified') as label, COUNT(*) as total, SUM(purchase_cost) as cost")
            ->groupBy('label')->get();
        $mergedCompany = [];
        foreach ($rawByCompany as $row) {
            $key = $resolveCo($row->label);
            if (! isset($mergedCompany[$key])) {
                $mergedCompany[$key] = (object) ['label' => $key, 'total' => 0, 'cost' => 0];
            }
            $mergedCompany[$key]->total += $row->total;
            $mergedCompany[$key]->cost += $row->cost;
        }
        $byCompanyOwned = collect($mergedCompany)->sortByDesc('total')->values();

        // By rental vendor. The vendor COMPANY comes off the vendor_id FK — rental_vendor
        // holds the vendor's PIC (a person) since the asset form started auto-filling it,
        // so grouping on that column alone would split one vendor across its contacts.
        // It remains the fallback for assets never linked to a registered vendor.
        $vendorLabel = "COALESCE(NULLIF(TRIM(vendors.name),''), NULLIF(TRIM(asset_inventories.rental_vendor),''), 'Unspecified')";

        $byRentalVendor = AssetInventory::where('ownership_type', 'rental')
            ->leftJoin('vendors', 'vendors.id', '=', 'asset_inventories.vendor_id')
            ->selectRaw("{$vendorLabel} as label, COUNT(*) as total, SUM(asset_inventories.rental_cost_per_month) as monthly_cost")
            ->groupBy('label')->orderByDesc('total')->get();

        // Condition breakdown
        $conditionBreakdown = AssetInventory::selectRaw("COALESCE(asset_condition, 'unknown') as cond, COUNT(*) as total")
            ->groupBy('cond')->orderByDesc('total')->get();

        // Rental by Vendor → Company Supplied To → Asset Type → Brand (normalized)
        $rawRentalBrand = AssetInventory::where('ownership_type', 'rental')
            ->leftJoin('vendors', 'vendors.id', '=', 'asset_inventories.vendor_id')
            ->selectRaw("{$vendorLabel} as vendor, COALESCE(NULLIF(TRIM(asset_inventories.company_supplied_to),''), 'Unspecified') as supplied_to, COALESCE(NULLIF(TRIM(asset_inventories.asset_type),''), 'Unspecified') as asset_type, asset_inventories.brand, COUNT(*) as total")
            ->groupBy('vendor', 'supplied_to', 'asset_type', 'brand')->get();
        // Normalize supplied_to company names then re-group
        $rawRentalBrand->transform(function ($row) use ($resolveCo) {
            $row->supplied_to = $resolveCo($row->supplied_to);

            return $row;
        });
        $rentalByVendorBrand = $rawRentalBrand
            ->groupBy('vendor')
            ->map(fn ($rows) => $rows->groupBy('supplied_to')->map(fn ($cRows) => $cRows->groupBy('asset_type')));

        // Company-Owned by Company & Brand (normalized)
        $rawEntityBrand = AssetInventory::where('ownership_type', 'company')
            ->selectRaw("COALESCE(NULLIF(TRIM(company_name),''), 'Unspecified') as company, brand, COUNT(*) as total")
            ->groupBy('company', 'brand')->get();
        $rawEntityBrand->transform(function ($row) use ($resolveCo) {
            $row->company = $resolveCo($row->company);

            return $row;
        });
        // Re-aggregate after normalization (merge duplicate company+brand combos)
        $mergedEntityBrand = [];
        foreach ($rawEntityBrand as $row) {
            $key = $row->company.'||'.$row->brand;
            if (! isset($mergedEntityBrand[$key])) {
                $mergedEntityBrand[$key] = (object) ['company' => $row->company, 'brand' => $row->brand, 'total' => 0];
            }
            $mergedEntityBrand[$key]->total += $row->total;
        }
        $companyByEntityBrand = collect($mergedEntityBrand)->values()
            ->sortBy('company')->groupBy('company')
            ->map(fn ($rows) => $rows->sortByDesc('total'));

        // Warranty expiring soon (next 90 days)
        $warrantyExpiring = AssetInventory::whereNotNull('warranty_expiry_date')
            ->where('warranty_expiry_date', '>=', now())
            ->where('warranty_expiry_date', '<=', now()->addDays(90))
            ->orderBy('warranty_expiry_date')
            ->get(['asset_tag', 'asset_type', 'brand', 'model', 'warranty_expiry_date', 'status']);

        // Rental contracts expiring soon (next 90 days)
        $rentalExpiring = AssetInventory::with('vendor')
            ->where('ownership_type', 'rental')
            ->whereNotNull('rental_end_date')
            ->where('rental_end_date', '>=', now())
            ->where('rental_end_date', '<=', now()->addDays(90))
            ->orderBy('rental_end_date')
            // vendor_id must be selected or the eager-loaded vendor is always null and the
            // Vendor column silently falls back to the PIC name.
            ->get(['asset_tag', 'asset_type', 'brand', 'model', 'vendor_id', 'rental_vendor', 'rental_end_date', 'rental_cost_per_month']);

        return view('reports.assets', compact(
            'companies',
            'statusBreakdown', 'byType', 'ownership',
            'byCompanyOwned', 'byRentalVendor', 'conditionBreakdown',
            'warrantyExpiring', 'rentalExpiring',
            'rentalByVendorBrand', 'companyByEntityBrand'
        ));
    }

    // ── Decommissioning: the single e-waste review surface + archive ───────────
    /**
     * The reports set (superadmin/hr_manager/system_admin) + it_manager + Finance, plus
     * anybody NAMED as a management approver — see User::canViewDecommissionReports().
     */
    private function authorizeDecommission(): void
    {
        if (! Auth::user()->canViewDecommissionReports()) {
            abort(403);
        }
    }

    /**
     * RETIRED (2026-08-20). This standalone page was, until then, the ONE place Finance and
     * management reviewed a disposal as well as the archive of finished ones — that role has
     * moved to the Company Asset Decommissioning tab on the Asset Listing
     * (AssetController::index() / buildDecommissionReview() / ewasteCycleReportsFor()), which
     * both Finance and named management approvers already reach from the sidebar. This route
     * and its name survive only as a redirect, so a bookmarked link, an emailed notification,
     * or an old bell entry still carrying this URL lands somewhere useful instead of a 404.
     * The heavy query this method used to run — and the `reports.decommission` view — are
     * gone; do not re-add either here. `year` is forwarded because the destination tab
     * understands the same filter.
     */
    public function decommissionReport(Request $request)
    {
        $this->authorizeDecommission();

        return redirect()->route('assets.index', array_filter([
            'tab' => 'company-decom',
            'year' => $request->integer('year') ?: null,
        ]));
    }

    /**
     * A named approver reaches this page scoped to their own companies, so the PDF routes have
     * to honour the same scope — the id is in the URL, and without this the listing would be
     * filtered while the document behind it stayed open to anybody who could guess a batch.
     */
    private function authorizeBatch(AssetDecommissionBatch $batch): void
    {
        $this->authorizeDecommission();

        $companies = Auth::user()->reachableDecommissionCompanies();

        abort_if($companies !== null && ! $companies->contains($batch->company), 403);
    }

    public function downloadBatchPdf(AssetDecommissionBatch $batch)
    {
        $this->authorizeBatch($batch);

        // Serve the archived PDF when present; otherwise render fresh.
        if ($batch->report_pdf_path && Storage::disk('local')->exists($batch->report_pdf_path)) {
            return Storage::disk('local')->download($batch->report_pdf_path, $batch->batch_number.'.pdf');
        }

        return response(DecommissionReportRenderer::render($batch), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$batch->batch_number.'.pdf"',
        ]);
    }

    /** Same report, served inline for viewing in the browser (not forced download). */
    public function viewBatchPdf(AssetDecommissionBatch $batch)
    {
        $this->authorizeBatch($batch);

        // Serve the archived PDF inline when present; otherwise render fresh, inline.
        if ($batch->report_pdf_path && Storage::disk('local')->exists($batch->report_pdf_path)) {
            return Storage::disk('local')->response(
                $batch->report_pdf_path,
                $batch->batch_number.'.pdf',
                ['Content-Type' => 'application/pdf']
            );
        }

        return response(DecommissionReportRenderer::render($batch), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$batch->batch_number.'.pdf"',
        ]);
    }
}
