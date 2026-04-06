<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\EmployeeSalary;
use App\Models\EmployeeSalaryItem;
use App\Models\LeaveApplication;
use App\Models\OvertimeRequest;
use App\Models\PayrollItem;
use App\Models\PayRun;
use App\Models\Payslip;
use App\Models\PayslipItem;
use App\Models\PayrollConfig;
use App\Models\SalaryAdjustment;
use App\Models\EaForm;
use App\Models\Company;
use App\Mail\EaFormReadyMail;
use App\Mail\PayslipReadyMail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class PayrollController extends Controller
{
    // ── Authorization helpers ───────────────────────────────────────────
    private function authorizePayrollView(): void
    {
        if (!Auth::user()->canViewPayroll()) {
            abort(403, 'Unauthorized access to payroll.');
        }
    }

    private function authorizePayrollManage(): void
    {
        if (!Auth::user()->canManagePayroll()) {
            abort(403, 'Unauthorized: payroll management requires HR Manager or SuperAdmin privileges.');
        }
    }

    private function authorizePayRunApprove(): void
    {
        if (!Auth::user()->canApprovePayRun()) {
            abort(403, 'Unauthorized: only HR Manager or SuperAdmin can approve pay runs.');
        }
    }

    private function authorizeEaFormManage(): void
    {
        if (!Auth::user()->canManageEaForms()) {
            abort(403, 'Unauthorized: EA form management requires HR Manager or SuperAdmin privileges.');
        }
    }

    // ── HR: Payroll Items (allowance/deduction types) ──────────────────
    public function items()
    {
        $this->authorizePayrollView();
        $items = PayrollItem::orderBy('type')->orderBy('name')->get();
        return view('hr.payroll.items', compact('items'));
    }

    public function storeItem(Request $request)
    {
        $this->authorizePayrollManage();
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'code' => 'required|string|max:30|unique:payroll_items,code',
            'company' => 'nullable|string|max:255',
            'type' => 'required|in:earning,deduction',
            'is_statutory' => 'boolean',
            'is_recurring' => 'boolean',
        ]);

        $data['is_statutory'] = $request->boolean('is_statutory');
        $data['is_recurring'] = $request->boolean('is_recurring');

        PayrollItem::create($data);

        return back()->with('success', 'Payroll item created.');
    }

    public function updateItem(Request $request, PayrollItem $item)
    {
        $this->authorizePayrollManage();
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'code' => 'required|string|max:30|unique:payroll_items,code,' . $item->id,
            'type' => 'required|in:earning,deduction',
            'is_statutory' => 'boolean',
            'is_recurring' => 'boolean',
            'is_active' => 'boolean',
        ]);

        $data['is_statutory'] = $request->boolean('is_statutory');
        $data['is_recurring'] = $request->boolean('is_recurring');
        $data['is_active'] = $request->boolean('is_active');

        $item->update($data);

        return back()->with('success', 'Payroll item updated.');
    }

    // ── HR: Employee Salary Setup ──────────────────────────────────────
    public function salaries()
    {
        $this->authorizePayrollView();
        $salaries = EmployeeSalary::with(['employee', 'items.payrollItem'])
            ->where('is_active', true)
            ->orderBy('employee_id')
            ->paginate(20);

        $employees = Employee::orderBy('full_name')->get();
        $payrollItems = PayrollItem::where('is_active', true)->where('is_recurring', true)->get();

        return view('hr.payroll.salaries', compact('salaries', 'employees', 'payrollItems'));
    }

    public function storeSalary(Request $request)
    {
        $this->authorizePayrollManage();
        $data = $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'basic_salary' => 'required|numeric|min:0',
            'payment_method' => 'required|in:bank_transfer,cheque,cash',
            'bank_name' => 'nullable|string|max:100',
            'bank_account_number' => 'nullable|string|max:50',
            'effective_from' => 'required|date',
            'items' => 'nullable|array',
            'items.*.payroll_item_id' => 'required|exists:payroll_items,id',
            'items.*.amount' => 'required|numeric|min:0',
        ]);

        // Deactivate previous salary and log adjustment
        $previous = EmployeeSalary::where('employee_id', $data['employee_id'])
            ->where('is_active', true)
            ->first();

        if ($previous) {
            $previous->update(['is_active' => false, 'effective_until' => $data['effective_from']]);

            SalaryAdjustment::create([
                'employee_id' => $data['employee_id'],
                'adjusted_by' => Auth::id(),
                'type' => 'adjustment',
                'previous_salary' => $previous->basic_salary,
                'new_salary' => $data['basic_salary'],
                'effective_date' => $data['effective_from'],
                'reason' => $request->input('reason', 'Salary structure updated'),
            ]);
        }

        $salary = EmployeeSalary::create([
            'employee_id' => $data['employee_id'],
            'basic_salary' => $data['basic_salary'],
            'payment_method' => $data['payment_method'],
            'bank_name' => $data['bank_name'] ?? null,
            'bank_account_number' => $data['bank_account_number'] ?? null,
            'effective_from' => $data['effective_from'],
            'is_active' => true,
        ]);

        if (!empty($data['items'])) {
            foreach ($data['items'] as $item) {
                EmployeeSalaryItem::create([
                    'employee_salary_id' => $salary->id,
                    'payroll_item_id' => $item['payroll_item_id'],
                    'amount' => $item['amount'],
                ]);
            }
        }

        return back()->with('success', 'Salary structure saved.');
    }

    // ── HR: Pay Runs ───────────────────────────────────────────────────
    public function index()
    {
        $this->authorizePayrollView();
        $payRuns = PayRun::withCount('payslips')
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->paginate(15);

        return view('hr.payroll.index', compact('payRuns'));
    }

    public function create()
    {
        $this->authorizePayrollManage();
        return view('hr.payroll.create');
    }

    public function store(Request $request)
    {
        $this->authorizePayrollManage();
        $data = $request->validate([
            'company' => 'nullable|string|max:255',
            'year' => 'required|integer|min:2024|max:2030',
            'month' => 'required|integer|min:1|max:12',
            'pay_date' => 'required|date',
            'notes' => 'nullable|string|max:1000',
        ]);

        $periodStart = \Carbon\Carbon::createFromDate($data['year'], $data['month'], 1)->startOfMonth();
        $periodEnd = $periodStart->copy()->endOfMonth();

        $reference = 'PR-' . $data['year'] . '-' . str_pad($data['month'], 2, '0', STR_PAD_LEFT);
        $title = $periodStart->format('F Y') . ' Payroll';

        $payRun = PayRun::create([
            'company' => $data['company'] ?? null,
            'reference' => $reference,
            'title' => $title,
            'year' => $data['year'],
            'month' => $data['month'],
            'pay_date' => $data['pay_date'],
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'status' => 'draft',
            'created_by' => Auth::id(),
        ]);

        return redirect()->route('hr.payroll.pay-runs.show', $payRun)
            ->with('success', 'Pay run created. Generate payslips to continue.');
    }

    public function show(PayRun $payRun)
    {
        $this->authorizePayrollView();
        $payRun->load('payslips.employee', 'creator', 'approver');
        return view('hr.payroll.show', compact('payRun'));
    }

    // ── Generate Payslips for a Pay Run ────────────────────────────────
    public function generatePayslips(PayRun $payRun)
    {
        $this->authorizePayrollManage();

        if ($payRun->status !== 'draft') {
            return back()->with('error', 'Payslips can only be generated for draft pay runs.');
        }

        DB::beginTransaction();
        try {
            // Get all active employees with salary structures
            $salaryQuery = EmployeeSalary::with(['employee', 'items.payrollItem'])
                ->where('is_active', true);

            if ($payRun->company) {
                $salaryQuery->whereHas('employee', function ($q) use ($payRun) {
                    $q->where('company', $payRun->company);
                });
            }

            $salaries = $salaryQuery->get();
            $totalGross = 0;
            $totalDeductions = 0;
            $totalNet = 0;
            $totalEmployerCost = 0;
            $count = 0;

            foreach ($salaries as $salary) {
                $employee = $salary->employee;
                $count++;

                $payslipNumber = $payRun->reference . '-' . str_pad($count, 4, '0', STR_PAD_LEFT);

                // Calculate unpaid leave for the period
                $unpaidLeaveDays = LeaveApplication::where('employee_id', $employee->id)
                    ->where('status', 'approved')
                    ->whereHas('leaveType', fn($q) => $q->where('is_paid', false))
                    ->where('start_date', '<=', $payRun->period_end)
                    ->where('end_date', '>=', $payRun->period_start)
                    ->sum('total_days');
                $workingDays = $salary->working_days_per_month ?? 26;
                $dailyRate = (float) $salary->basic_salary / $workingDays;
                $unpaidLeaveAmount = round($unpaidLeaveDays * $dailyRate, 2);

                // Calculate approved overtime
                $overtimeData = OvertimeRequest::where('employee_id', $employee->id)
                    ->where('status', 'approved')
                    ->whereBetween('date', [$payRun->period_start, $payRun->period_end])
                    ->selectRaw('SUM(hours) as total_hours, SUM(hours * multiplier * ?) as total_amount', [$dailyRate / 8])
                    ->first();
                $otHours = (float) ($overtimeData->total_hours ?? 0);
                $otAmount = round((float) ($overtimeData->total_amount ?? 0), 2);

                // Earnings = basic + recurring allowances + overtime
                $recurringEarnings = $salary->items
                    ->filter(fn($i) => $i->payrollItem && $i->payrollItem->type === 'earning')
                    ->sum('amount');
                $totalEarnings = (float) $salary->basic_salary + (float) $recurringEarnings + $otAmount - $unpaidLeaveAmount;

                // Calculate approved expense claims for this period
                $approvedClaims = \App\Models\ExpenseClaim::where('employee_id', $employee->id)
                    ->where('status', 'hr_approved')
                    ->where(function ($q) use ($payRun) {
                        $q->where('year', $payRun->period_start->year)
                          ->where('month', $payRun->period_start->month);
                    })
                    ->get();
                $claimReimbursement = $approvedClaims->sum('total_with_gst');
                $totalEarnings += (float) $claimReimbursement;

                $payslip = Payslip::create([
                    'pay_run_id' => $payRun->id,
                    'employee_id' => $employee->id,
                    'payslip_number' => $payslipNumber,
                    'basic_salary' => $salary->basic_salary,
                    'total_earnings' => $totalEarnings,
                    'unpaid_leave_days' => $unpaidLeaveDays,
                    'unpaid_leave_amount' => $unpaidLeaveAmount,
                    'overtime_hours' => $otHours,
                    'overtime_amount' => $otAmount,
                    'status' => 'draft',
                ]);

                // Create payslip line items from recurring salary items
                foreach ($salary->items as $item) {
                    if ($item->payrollItem) {
                        PayslipItem::create([
                            'payslip_id' => $payslip->id,
                            'payroll_item_id' => $item->payroll_item_id,
                            'description' => $item->payrollItem->name,
                            'type' => $item->payrollItem->type,
                            'amount' => $item->amount,
                            'is_statutory' => $item->payrollItem->is_statutory,
                        ]);
                    }
                }

                // Calculate statutory deductions
                $payslip->calculateStatutory();
                $payslip->save();

                // Link approved claims to this payslip & add as reimbursement line item
                if ($claimReimbursement > 0) {
                    foreach ($approvedClaims as $claim) {
                        $claim->update([
                            'payslip_id' => $payslip->id,
                            'pay_run_id' => $payRun->id,
                            'status' => 'paid',
                        ]);
                    }
                    PayslipItem::create([
                        'payslip_id' => $payslip->id,
                        'payroll_item_id' => null,
                        'description' => 'Expense Claim Reimbursement',
                        'type' => 'earning',
                        'amount' => $claimReimbursement,
                        'is_statutory' => false,
                    ]);
                }

                $totalGross += $payslip->total_earnings;
                $totalDeductions += $payslip->total_deductions;
                $totalNet += $payslip->net_pay;
                $totalEmployerCost += $payslip->epf_employer + $payslip->socso_employer
                    + $payslip->eis_employer + $payslip->hrdf_amount;
            }

            $payRun->update([
                'status' => 'processing',
                'total_gross' => $totalGross,
                'total_deductions' => $totalDeductions,
                'total_net' => $totalNet,
                'total_employer_cost' => $totalEmployerCost,
            ]);

            DB::commit();

            return back()->with('success', $count . ' payslips generated.');
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->with('error', 'Error generating payslips: ' . $e->getMessage());
        }
    }

    // ── Approve Pay Run ────────────────────────────────────────────────
    public function approvePayRun(PayRun $payRun)
    {
        $this->authorizePayRunApprove();

        if ($payRun->status !== 'processing') {
            return back()->with('error', 'Pay run must be in processing status to approve.');
        }

        $payRun->update([
            'status' => 'approved',
            'approved_by' => Auth::id(),
            'approved_at' => now(),
        ]);

        $payRun->payslips()->update(['status' => 'finalized']);

        // Send payslip-ready email to each employee
        $payRun->load('payslips.employee.user');
        foreach ($payRun->payslips as $payslip) {
            $emp = $payslip->employee;
            if ($emp && $emp->user && $emp->user->work_email) {
                try {
                    Mail::to($emp->user->work_email)->send(new PayslipReadyMail($emp, $payRun));
                } catch (\Exception $e) {
                    Log::warning('Failed to send payslip notification to employee #' . $emp->id . ': ' . $e->getMessage());
                }
            }
        }

        return back()->with('success', 'Pay run approved and employees notified.');
    }

    // ── Mark as Paid ───────────────────────────────────────────────────
    public function markPaid(PayRun $payRun)
    {
        $this->authorizePayRunApprove();

        if ($payRun->status !== 'approved') {
            return back()->with('error', 'Pay run must be approved before marking as paid.');
        }

        $payRun->update(['status' => 'paid']);
        $payRun->payslips()->update(['status' => 'paid']);

        return back()->with('success', 'Pay run marked as paid.');
    }

    // ── Employee: My Payslips ──────────────────────────────────────────
    public function myPayslips()
    {
        $employee = Auth::user()->employee;
        if (!$employee) {
            return redirect()->route(Auth::user()->isHr() || Auth::user()->isSuperadmin() || Auth::user()->isSystemAdmin() ? 'hr.dashboard' : (Auth::user()->isIt() ? 'it.dashboard' : 'user.dashboard'))->with('error', 'No employee profile found.');
        }

        $payslips = Payslip::with(['payRun', 'items'])
            ->where('employee_id', $employee->id)
            ->whereIn('status', ['finalized', 'paid'])
            ->orderByDesc('created_at')
            ->paginate(12);

        return view('user.payroll.index', compact('payslips', 'employee'));
    }

    public function viewPayslip(Payslip $payslip)
    {
        $employee = Auth::user()->employee;

        if (!$employee || $payslip->employee_id !== $employee->id) {
            abort(403);
        }

        $payslip->load(['payRun', 'employee', 'items.payrollItem']);

        return view('user.payroll.payslip', compact('payslip'));
    }

    public function viewPayslipHr(Payslip $payslip)
    {
        $this->authorizePayrollView();
        $payslip->load(['payRun', 'employee', 'items.payrollItem']);

        return view('hr.payroll.payslip', compact('payslip'));
    }

    // ── Payroll Configuration ──────────────────────────────────────────
    public function config()
    {
        $this->authorizePayrollManage();
        $config = PayrollConfig::forCompany();
        return view('hr.payroll.config', compact('config'));
    }

    public function updateConfig(Request $request)
    {
        $this->authorizePayrollManage();
        $data = $request->validate([
            'epf_employee_rate' => 'required|numeric|min:0|max:100',
            'epf_employer_rate' => 'required|numeric|min:0|max:100',
            'socso_employee_rate' => 'required|numeric|min:0|max:100',
            'socso_employer_rate' => 'required|numeric|min:0|max:100',
            'socso_wage_ceiling' => 'required|numeric|min:0',
            'eis_rate' => 'required|numeric|min:0|max:100',
            'eis_wage_ceiling' => 'required|numeric|min:0',
            'hrdf_rate' => 'required|numeric|min:0|max:100',
            'hrdf_enabled' => 'boolean',
            'default_working_days' => 'required|integer|min:1|max:31',
            'bank_name' => 'nullable|string|max:100',
            'bank_account_number' => 'nullable|string|max:50',
            'lhdn_employer_no' => 'nullable|string|max:50',
            'epf_employer_no' => 'nullable|string|max:50',
            'socso_employer_no' => 'nullable|string|max:50',
            'eis_employer_no' => 'nullable|string|max:50',
        ]);

        $data['hrdf_enabled'] = $request->boolean('hrdf_enabled');

        PayrollConfig::updateOrCreate(
            ['company' => null],
            $data
        );

        return back()->with('success', 'Payroll configuration updated.');
    }

    // ── Salary Adjustments (audit log) ─────────────────────────────────
    public function adjustments(Employee $employee)
    {
        $this->authorizePayrollView();
        $adjustments = SalaryAdjustment::where('employee_id', $employee->id)
            ->with('adjustedBy')
            ->orderByDesc('effective_date')
            ->get();

        return view('hr.payroll.adjustments', compact('employee', 'adjustments'));
    }

    // ══════════════════════════════════════════════════════════════════════
    // EA FORM (Borang EA / CP.8D)
    // ══════════════════════════════════════════════════════════════════════

    public function eaForms(Request $request)
    {
        $this->authorizeEaFormManage();
        $year = (int) $request->input('year', now()->year - 1);
        $eaForms = EaForm::with('employee')
            ->where('year', $year)
            ->orderBy('employee_name')
            ->paginate(25);

        $availableYears = EaForm::selectRaw('DISTINCT year')->orderByDesc('year')->pluck('year');
        if ($availableYears->isEmpty()) {
            $availableYears = collect([now()->year - 1]);
        }

        return view('hr.payroll.ea-forms.index', compact('eaForms', 'year', 'availableYears'));
    }

    public function generateEaForms(Request $request)
    {
        $this->authorizeEaFormManage();
        $year = (int) $request->input('year', now()->year - 1);

        $payRuns = PayRun::where('year', $year)
            ->whereIn('status', ['approved', 'paid'])
            ->pluck('id');

        if ($payRuns->isEmpty()) {
            return back()->with('error', "No approved/paid pay runs found for {$year}. Generate and approve pay runs first.");
        }

        $config  = PayrollConfig::forCompany();
        $company = Company::first();

        $employeeIds = Payslip::whereIn('pay_run_id', $payRuns)
            ->distinct()
            ->pluck('employee_id');

        $employees = Employee::whereIn('id', $employeeIds)->get();
        $count = 0;

        foreach ($employees as $employee) {
            if (EaForm::where('employee_id', $employee->id)->where('year', $year)->exists()) {
                continue;
            }

            $payslips = Payslip::where('employee_id', $employee->id)
                ->whereIn('pay_run_id', $payRuns)
                ->get();

            $grossSalary    = $payslips->sum('basic_salary');
            $overtimePay    = $payslips->sum('overtime_amount');
            $totalEarnings  = $payslips->sum('total_earnings');
            $allowances     = $totalEarnings - $grossSalary - $overtimePay;
            $grossRemun     = $totalEarnings;

            $epfEe   = $payslips->sum('epf_employee');
            $socsoEe = $payslips->sum('socso_employee');
            $eisEe   = $payslips->sum('eis_employee');
            $pcb     = $payslips->sum('pcb_amount');

            $epfEr   = $payslips->sum('epf_employer');
            $socsoEr = $payslips->sum('socso_employer');
            $eisEr   = $payslips->sum('eis_employer');
            $hrdfEr  = $payslips->sum('hrdf_amount');

            $totalDeductions = $epfEe + $socsoEe + $eisEe + $pcb;

            EaForm::create([
                'employee_id'        => $employee->id,
                'year'               => $year,
                'employer_name'      => $company?->name ?? config('app.name'),
                'employer_address'   => $company?->address ?? '',
                'employer_tax_no'    => $config->lhdn_employer_no ?? '',
                'employee_name'      => $employee->full_name,
                'employee_tax_no'    => $employee->income_tax_no,
                'employee_ic_no'     => $employee->official_document_id,
                'employee_epf_no'    => $employee->epf_no,
                'employee_socso_no'  => $employee->socso_no,
                'designation'        => $employee->designation,
                'employment_start_date' => $employee->start_date,
                'employment_end_date'   => $employee->exit_date,
                'gross_salary'       => $grossSalary,
                'overtime_pay'       => $overtimePay,
                'commission'         => 0,
                'allowances'         => max(0, $allowances),
                'gross_remuneration' => $grossRemun,
                'benefits_in_kind'   => 0,
                'value_of_living_accommodation' => 0,
                'pension_or_annuity' => 0,
                'gratuity'           => 0,
                'total_remuneration' => $grossRemun,
                'epf_employee'       => $epfEe,
                'socso_employee'     => $socsoEe,
                'eis_employee'       => $eisEe,
                'pcb_paid'           => $pcb,
                'zakat'              => 0,
                'total_deductions'   => $totalDeductions,
                'epf_employer'       => $epfEr,
                'socso_employer'     => $socsoEr,
                'eis_employer'       => $eisEr,
                'hrdf_employer'      => $hrdfEr,
                'status'             => 'draft',
                'generated_by'       => Auth::id(),
            ]);
            $count++;
        }

        if ($count === 0) {
            return back()->with('info', "EA forms already exist for all employees in {$year}.");
        }

        return redirect()->route('hr.payroll.ea-forms.index', ['year' => $year])
            ->with('success', "{$count} EA form(s) generated for {$year}.");
    }

    public function showEaForm(EaForm $eaForm)
    {
        $this->authorizeEaFormManage();
        $eaForm->load('employee', 'generator');
        return view('hr.payroll.ea-forms.show', compact('eaForm'));
    }

    public function updateEaForm(Request $request, EaForm $eaForm)
    {
        $this->authorizeEaFormManage();

        if ($eaForm->status === 'finalized') {
            return back()->with('error', 'Cannot edit a finalized EA form.');
        }

        $data = $request->validate([
            'benefits_in_kind'             => 'nullable|numeric|min:0',
            'value_of_living_accommodation' => 'nullable|numeric|min:0',
            'pension_or_annuity'           => 'nullable|numeric|min:0',
            'gratuity'                     => 'nullable|numeric|min:0',
            'commission'                   => 'nullable|numeric|min:0',
            'zakat'                        => 'nullable|numeric|min:0',
            'notes'                        => 'nullable|string|max:1000',
        ]);

        $grossRemun = (float) $eaForm->gross_salary + (float) $eaForm->overtime_pay
            + (float) ($data['commission'] ?? $eaForm->commission)
            + (float) $eaForm->allowances;
        $totalRemun = $grossRemun
            + (float) ($data['benefits_in_kind'] ?? $eaForm->benefits_in_kind)
            + (float) ($data['value_of_living_accommodation'] ?? $eaForm->value_of_living_accommodation)
            + (float) ($data['pension_or_annuity'] ?? $eaForm->pension_or_annuity)
            + (float) ($data['gratuity'] ?? $eaForm->gratuity);
        $totalDeductions = (float) $eaForm->epf_employee + (float) $eaForm->socso_employee
            + (float) $eaForm->eis_employee + (float) $eaForm->pcb_paid
            + (float) ($data['zakat'] ?? $eaForm->zakat);

        $data['gross_remuneration'] = $grossRemun;
        $data['total_remuneration'] = $totalRemun;
        $data['total_deductions']   = $totalDeductions;

        $eaForm->update($data);

        return back()->with('success', 'EA form updated.');
    }

    public function finalizeEaForm(EaForm $eaForm)
    {
        $this->authorizeEaFormManage();
        $eaForm->update([
            'status'       => 'finalized',
            'finalized_at' => now(),
        ]);

        // Send EA form ready email
        $employee = $eaForm->employee;
        if ($employee && $employee->user && $employee->user->work_email) {
            try {
                Mail::to($employee->user->work_email)->send(new EaFormReadyMail($employee, $eaForm));
            } catch (\Exception $e) {
                Log::warning('Failed to send EA form notification to employee #' . $employee->id . ': ' . $e->getMessage());
            }
        }

        return back()->with('success', 'EA form finalized for ' . $eaForm->employee_name . '.');
    }

    public function bulkFinalizeEaForms(Request $request)
    {
        $this->authorizeEaFormManage();
        $year = (int) $request->input('year', now()->year - 1);

        // Get draft forms before bulk update so we can notify
        $draftForms = EaForm::with('employee.user')
            ->where('year', $year)
            ->where('status', 'draft')
            ->get();

        $count = EaForm::where('year', $year)
            ->where('status', 'draft')
            ->update(['status' => 'finalized', 'finalized_at' => now()]);

        // Send EA form ready emails
        foreach ($draftForms as $eaForm) {
            $employee = $eaForm->employee;
            if ($employee && $employee->user && $employee->user->work_email) {
                try {
                    $eaForm->refresh(); // reload with finalized status
                    Mail::to($employee->user->work_email)->send(new EaFormReadyMail($employee, $eaForm));
                } catch (\Exception $e) {
                    Log::warning('Failed to send EA form notification to employee #' . $employee->id . ': ' . $e->getMessage());
                }
            }
        }

        return back()->with('success', "{$count} EA form(s) finalized for {$year}.");
    }

    public function deleteEaForm(EaForm $eaForm)
    {
        $this->authorizeEaFormManage();

        if ($eaForm->status === 'finalized') {
            return back()->with('error', 'Cannot delete a finalized EA form.');
        }

        $eaForm->delete();
        return back()->with('success', 'EA form deleted.');
    }

    public function myEaForm(Request $request)
    {
        $employee = Auth::user()->employee;
        if (!$employee) {
            return redirect()->route('user.dashboard')->with('error', 'No employee profile found.');
        }

        $year = (int) $request->input('year', now()->year - 1);

        $eaForms = EaForm::where('employee_id', $employee->id)
            ->where('status', 'finalized')
            ->orderByDesc('year')
            ->get();

        $currentForm = $eaForms->firstWhere('year', $year);
        $availableYears = $eaForms->pluck('year')->unique()->sort()->reverse()->values();

        return view('user.payroll.ea-form', compact('currentForm', 'availableYears', 'year', 'employee'));
    }
}
