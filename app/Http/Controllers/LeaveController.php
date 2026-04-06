<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\LeaveApplication;
use App\Models\LeaveBalance;
use App\Models\LeaveEntitlement;
use App\Models\LeaveType;
use App\Models\PublicHoliday;
use App\Mail\LeaveApplicationNotifyMail;
use App\Mail\LeaveApprovalNotifyMail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class LeaveController extends Controller
{
    /** Abort 403 unless user can view leave admin pages. */
    private function authorizeLeaveAdmin(): void
    {
        if (!Auth::user()->canViewLeaveAdmin()) {
            abort(403);
        }
    }

    /** Abort 403 unless user can manage leave settings (types, entitlements, balances, holidays). */
    private function authorizeLeaveManager(): void
    {
        if (!Auth::user()->canManageLeave()) {
            abort(403);
        }
    }

    // ── HR: Leave Types Management ─────────────────────────────────────
    public function types()
    {
        $this->authorizeLeaveAdmin();
        $types = LeaveType::orderBy('sort_order')->get();
        return view('hr.leave.types', compact('types'));
    }

    public function storeType(Request $request)
    {
        $this->authorizeLeaveManager();
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'code' => 'required|string|max:20|unique:leave_types,code',
            'company' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:500',
            'is_paid' => 'boolean',
            'requires_attachment' => 'boolean',
        ]);

        $data['is_paid'] = $request->boolean('is_paid');
        $data['requires_attachment'] = $request->boolean('requires_attachment');

        LeaveType::create($data);

        return back()->with('success', 'Leave type created.');
    }

    public function updateType(Request $request, LeaveType $leaveType)
    {
        $this->authorizeLeaveManager();
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'description' => 'nullable|string|max:500',
            'is_paid' => 'boolean',
            'requires_attachment' => 'boolean',
            'is_active' => 'boolean',
        ]);

        $data['is_paid'] = $request->boolean('is_paid');
        $data['requires_attachment'] = $request->boolean('requires_attachment');
        $data['is_active'] = $request->boolean('is_active');

        $leaveType->update($data);

        return back()->with('success', 'Leave type updated.');
    }

    // ── HR: Entitlements ───────────────────────────────────────────────
    public function entitlements()
    {
        $this->authorizeLeaveAdmin();
        $entitlements = LeaveEntitlement::with('leaveType')->get();
        $leaveTypes = LeaveType::where('is_active', true)->get();
        return view('hr.leave.entitlements', compact('entitlements', 'leaveTypes'));
    }

    public function storeEntitlement(Request $request)
    {
        $this->authorizeLeaveManager();
        $data = $request->validate([
            'leave_type_id' => 'required|exists:leave_types,id',
            'company' => 'nullable|string|max:255',
            'min_tenure_months' => 'required|integer|min:0',
            'max_tenure_months' => 'nullable|integer|min:0',
            'entitled_days' => 'required|numeric|min:0',
            'carry_forward_limit' => 'required|numeric|min:0',
        ]);

        LeaveEntitlement::create($data);

        return back()->with('success', 'Entitlement created.');
    }

    public function updateEntitlement(Request $request, LeaveEntitlement $entitlement)
    {
        $this->authorizeLeaveManager();
        $data = $request->validate([
            'leave_type_id' => 'required|exists:leave_types,id',
            'company' => 'nullable|string|max:255',
            'min_tenure_months' => 'required|integer|min:0',
            'max_tenure_months' => 'nullable|integer|min:0',
            'entitled_days' => 'required|numeric|min:0',
            'carry_forward_limit' => 'required|numeric|min:0',
        ]);

        $entitlement->update($data);

        return back()->with('success', 'Entitlement updated.');
    }

    public function destroyEntitlement(LeaveEntitlement $entitlement)
    {
        $this->authorizeLeaveManager();
        $entitlement->delete();

        return back()->with('success', 'Entitlement deleted.');
    }

    // ── HR: Public Holidays ────────────────────────────────────────────
    public function holidays()
    {
        $this->authorizeLeaveAdmin();
        $holidays = PublicHoliday::orderBy('date')->get();
        return view('hr.leave.holidays', compact('holidays'));
    }

    public function storeHoliday(Request $request)
    {
        $this->authorizeLeaveManager();
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'date' => 'required|date',
            'company' => 'nullable|string|max:255',
            'is_recurring' => 'boolean',
        ]);

        $data['year'] = date('Y', strtotime($data['date']));
        $data['is_recurring'] = $request->boolean('is_recurring');

        PublicHoliday::create($data);

        return back()->with('success', 'Public holiday added.');
    }

    public function updateHoliday(Request $request, PublicHoliday $holiday)
    {
        $this->authorizeLeaveManager();
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'date' => 'required|date',
            'company' => 'nullable|string|max:255',
            'is_recurring' => 'boolean',
        ]);

        $data['year'] = date('Y', strtotime($data['date']));
        $data['is_recurring'] = $request->boolean('is_recurring');

        $holiday->update($data);

        return back()->with('success', 'Public holiday updated.');
    }

    public function destroyHoliday(PublicHoliday $holiday)
    {
        $this->authorizeLeaveManager();
        $holiday->delete();
        return back()->with('success', 'Public holiday removed.');
    }

    // ── HR: All Leave Applications ─────────────────────────────────────
    public function index(Request $request)
    {
        $this->authorizeLeaveAdmin();
        $query = LeaveApplication::with(['employee', 'leaveType', 'approver'])
            ->orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->employee_id);
        }

        $applications = $query->paginate(20);
        $employees = Employee::where('employment_status', 'active')->orderBy('full_name')->get();

        return view('hr.leave.index', compact('applications', 'employees'));
    }

    public function approve(LeaveApplication $application)
    {
        $this->authorizeLeaveManager();
        if ($application->status !== 'pending') {
            return back()->with('error', 'This application is no longer pending.');
        }

        $application->update([
            'status' => 'approved',
            'approved_by' => Auth::id(),
            'approved_at' => now(),
        ]);

        // Update leave balance
        $balance = LeaveBalance::firstOrCreate(
            [
                'employee_id' => $application->employee_id,
                'leave_type_id' => $application->leave_type_id,
                'year' => $application->start_date->year,
            ],
            ['entitled' => 0, 'taken' => 0, 'carry_forward' => 0, 'adjustment' => 0]
        );
        $balance->increment('taken', $application->total_days);

        // Notify employee of approval
        $application->load(['employee.user', 'leaveType']);
        $employee = $application->employee;
        if ($employee?->user?->work_email) {
            try {
                Mail::to($employee->user->work_email)->send(new LeaveApprovalNotifyMail(
                    $application, $employee, 'approved', Auth::user()->name, 'hr'
                ));
            } catch (\Exception $e) {
                Log::warning('Failed to send leave approval email to employee #' . $employee->id . ': ' . $e->getMessage());
            }
        }

        return back()->with('success', 'Leave approved. Employee has been notified.');
    }

    public function reject(Request $request, LeaveApplication $application)
    {
        $this->authorizeLeaveManager();
        if ($application->status !== 'pending') {
            return back()->with('error', 'This application is no longer pending.');
        }

        $data = $request->validate([
            'rejection_reason' => 'required|string|max:500',
        ]);

        $application->update([
            'status' => 'rejected',
            'approved_by' => Auth::id(),
            'approved_at' => now(),
            'rejection_reason' => $data['rejection_reason'],
        ]);

        // Notify employee of rejection
        $application->load(['employee.user', 'leaveType']);
        $employee = $application->employee;
        if ($employee?->user?->work_email) {
            try {
                Mail::to($employee->user->work_email)->send(new LeaveApprovalNotifyMail(
                    $application, $employee, 'rejected', Auth::user()->name, 'hr'
                ));
            } catch (\Exception $e) {
                Log::warning('Failed to send leave rejection email to employee #' . $employee->id . ': ' . $e->getMessage());
            }
        }

        return back()->with('success', 'Leave rejected. Employee has been notified.');
    }

    // ── HR: Leave Balances Overview ────────────────────────────────────
    public function balances(Request $request)
    {
        $this->authorizeLeaveAdmin();
        $year = $request->input('year', now()->year);
        $balances = LeaveBalance::with(['employee', 'leaveType'])
            ->where('year', $year)
            ->whereHas('employee', fn($q) => $q->where('employment_status', 'active'))
            ->orderBy('employee_id')
            ->get()
            ->groupBy('employee_id');

        $leaveTypes = LeaveType::where('is_active', true)->orderBy('sort_order')->get();
        $employees = Employee::where('employment_status', 'active')->orderBy('full_name')->get();

        return view('hr.leave.balances', compact('balances', 'leaveTypes', 'employees', 'year'));
    }

    // ── HR: Initialize Balances for Year ───────────────────────────────
    public function initializeBalances(Request $request)
    {
        $this->authorizeLeaveManager();
        $year = $request->input('year', now()->year);
        $employees = Employee::where('employment_status', 'active')->get();
        $leaveTypes = LeaveType::where('is_active', true)->get();
        $entitlements = LeaveEntitlement::all();
        $count = 0;

        foreach ($employees as $employee) {
            $tenureMonths = $employee->start_date
                ? $employee->start_date->diffInMonths(now())
                : 0;

            foreach ($leaveTypes as $type) {
                $existing = LeaveBalance::where('employee_id', $employee->id)
                    ->where('leave_type_id', $type->id)
                    ->where('year', $year)
                    ->exists();
                if ($existing) continue;

                $entitlement = $entitlements
                    ->where('leave_type_id', $type->id)
                    ->filter(function ($e) use ($tenureMonths) {
                        return $tenureMonths >= $e->min_tenure_months
                            && ($e->max_tenure_months === null || $tenureMonths <= $e->max_tenure_months);
                    })
                    ->first();

                $entitled = $entitlement ? $entitlement->entitled_days : 0;

                // Carry forward from previous year
                $carryForward = 0;
                $previousBalance = LeaveBalance::where('employee_id', $employee->id)
                    ->where('leave_type_id', $type->id)
                    ->where('year', $year - 1)
                    ->first();
                if ($previousBalance && $entitlement) {
                    $available = $previousBalance->entitled + $previousBalance->carry_forward
                        + $previousBalance->adjustment - $previousBalance->taken;
                    $carryForward = min(max($available, 0), $entitlement->carry_forward_limit);
                }

                LeaveBalance::create([
                    'employee_id' => $employee->id,
                    'leave_type_id' => $type->id,
                    'year' => $year,
                    'entitled' => $entitled,
                    'carry_forward' => $carryForward,
                    'taken' => 0,
                    'adjustment' => 0,
                ]);
                $count++;
            }
        }

        return back()->with('success', $count . ' leave balances initialized for ' . $year . '.');
    }

    // ── Employee: My Leave ─────────────────────────────────────────────
    public function myLeave()
    {
        $employee = Auth::user()->employee;
        if (!$employee) {
            return redirect()->route(Auth::user()->isHr() || Auth::user()->isSuperadmin() || Auth::user()->isSystemAdmin() ? 'hr.dashboard' : (Auth::user()->isIt() ? 'it.dashboard' : 'user.dashboard'))->with('error', 'No employee profile found.');
        }

        $year = now()->year;

        // Auto-initialize leave balances for the current year if none exist
        $balances = LeaveBalance::with('leaveType')
            ->where('employee_id', $employee->id)
            ->where('year', $year)
            ->get();

        if ($balances->isEmpty()) {
            $this->initializeEmployeeBalances($employee, $year);
            $balances = LeaveBalance::with('leaveType')
                ->where('employee_id', $employee->id)
                ->where('year', $year)
                ->get();
        }

        $applications = LeaveApplication::with('leaveType')
            ->where('employee_id', $employee->id)
            ->orderByDesc('created_at')
            ->paginate(15);

        // Pending days per leave type (awaiting approval — not yet deducted from balance)
        $pendingByType = LeaveApplication::where('employee_id', $employee->id)
            ->where('status', 'pending')
            ->whereYear('start_date', now()->year)
            ->selectRaw('leave_type_id, SUM(total_days) as pending_days')
            ->groupBy('leave_type_id')
            ->pluck('pending_days', 'leave_type_id');

        // Upcoming approved leave (next 30 days)
        $upcomingLeave = LeaveApplication::with('leaveType')
            ->where('employee_id', $employee->id)
            ->where('status', 'approved')
            ->where('start_date', '>=', now()->toDateString())
            ->where('start_date', '<=', now()->addDays(30)->toDateString())
            ->orderBy('start_date')
            ->get();

        // Year-to-date usage summary
        $ytdUsage = LeaveApplication::where('employee_id', $employee->id)
            ->where('status', 'approved')
            ->whereYear('start_date', now()->year)
            ->sum('total_days');

        $leaveTypes = LeaveType::where('is_active', true)->orderBy('sort_order')->get();

        return view('user.leave.index', compact(
            'applications', 'balances', 'leaveTypes', 'employee',
            'pendingByType', 'upcomingLeave', 'ytdUsage'
        ));
    }

    /**
     * Auto-initialize leave balances for a single employee for the given year.
     */
    private function initializeEmployeeBalances(Employee $employee, int $year): void
    {
        $leaveTypes   = LeaveType::where('is_active', true)->get();
        $entitlements = LeaveEntitlement::all();
        $tenureMonths = $employee->start_date
            ? $employee->start_date->diffInMonths(now())
            : 0;

        foreach ($leaveTypes as $type) {
            $existing = LeaveBalance::where('employee_id', $employee->id)
                ->where('leave_type_id', $type->id)
                ->where('year', $year)
                ->exists();
            if ($existing) continue;

            $entitlement = $entitlements
                ->where('leave_type_id', $type->id)
                ->filter(fn($e) => $tenureMonths >= $e->min_tenure_months
                    && ($e->max_tenure_months === null || $tenureMonths <= $e->max_tenure_months))
                ->first();

            $entitled = $entitlement ? $entitlement->entitled_days : 0;

            // Carry forward from previous year
            $carryForward = 0;
            $previousBalance = LeaveBalance::where('employee_id', $employee->id)
                ->where('leave_type_id', $type->id)
                ->where('year', $year - 1)
                ->first();
            if ($previousBalance && $entitlement) {
                $available = $previousBalance->entitled + $previousBalance->carry_forward
                    + $previousBalance->adjustment - $previousBalance->taken;
                $carryForward = min(max($available, 0), $entitlement->carry_forward_limit);
            }

            LeaveBalance::create([
                'employee_id'   => $employee->id,
                'leave_type_id' => $type->id,
                'year'          => $year,
                'entitled'      => $entitled,
                'carry_forward' => $carryForward,
                'taken'         => 0,
                'adjustment'    => 0,
            ]);
        }
    }

    public function apply(Request $request)
    {
        $employee = Auth::user()->employee;
        if (!$employee) {
            return back()->with('error', 'No employee profile found.');
        }

        $data = $request->validate([
            'leave_type_id' => 'required|exists:leave_types,id',
            'start_date' => 'required|date|after_or_equal:today',
            'end_date' => 'required|date|after_or_equal:start_date',
            'is_half_day' => 'boolean',
            'half_day_period' => 'nullable|in:morning,afternoon',
            'reason' => 'nullable|string|max:1000',
            'attachment' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120|valid_file_content',
        ]);

        $data['is_half_day'] = $request->boolean('is_half_day');

        // Calculate total days (basic: count business days)
        $start = \Carbon\Carbon::parse($data['start_date']);
        $end = \Carbon\Carbon::parse($data['end_date']);
        $totalDays = 0;
        $current = $start->copy();
        while ($current->lte($end)) {
            if (!$current->isWeekend()) {
                $totalDays++;
            }
            $current->addDay();
        }
        if ($data['is_half_day']) {
            $totalDays = 0.5;
        }

        // Check balance
        $balance = LeaveBalance::where('employee_id', $employee->id)
            ->where('leave_type_id', $data['leave_type_id'])
            ->where('year', $start->year)
            ->first();

        if ($balance && $balance->available < $totalDays) {
            return back()->with('error', 'Insufficient leave balance. Available: ' . $balance->available . ' days.');
        }

        $attachmentPath = null;
        if ($request->hasFile('attachment')) {
            $attachmentPath = $request->file('attachment')->store('leave-attachments', 'local');
        }

        $application = LeaveApplication::create([
            'employee_id' => $employee->id,
            'leave_type_id' => $data['leave_type_id'],
            'start_date' => $data['start_date'],
            'end_date' => $data['end_date'],
            'total_days' => $totalDays,
            'is_half_day' => $data['is_half_day'],
            'half_day_period' => $data['half_day_period'] ?? null,
            'reason' => $data['reason'] ?? null,
            'attachment_path' => $attachmentPath,
            'status' => 'pending',
            'manager_status' => 'pending',
        ]);

        $application->load('leaveType');

        // Notify reporting manager
        $this->notifyManager($application, $employee);

        // Notify HR
        $this->notifyHr($application, $employee);

        return back()->with('success', 'Leave application submitted. Your manager and HR have been notified.');
    }

    public function cancel(LeaveApplication $application)
    {
        $employee = Auth::user()->employee;
        if (!$employee || $application->employee_id !== $employee->id) {
            abort(403);
        }

        if (!in_array($application->status, ['pending'])) {
            return back()->with('error', 'Only pending applications can be cancelled.');
        }

        $application->update(['status' => 'cancelled']);

        return back()->with('success', 'Leave application cancelled.');
    }

    // ── HR: Team Calendar ──────────────────────────────────────────────
    public function calendar(Request $request)
    {
        $this->authorizeLeaveAdmin();

        $month = $request->input('month', now()->month);
        $year = $request->input('year', now()->year);

        $start = \Carbon\Carbon::createFromDate($year, $month, 1)->startOfMonth();
        $end = $start->copy()->endOfMonth();

        $leaves = LeaveApplication::with(['employee', 'leaveType'])
            ->where('status', 'approved')
            ->where(function ($q) use ($start, $end) {
                $q->whereBetween('start_date', [$start, $end])
                    ->orWhereBetween('end_date', [$start, $end])
                    ->orWhere(function ($q2) use ($start, $end) {
                        $q2->where('start_date', '<=', $start)
                            ->where('end_date', '>=', $end);
                    });
            })
            ->get();

        $holidays = PublicHoliday::whereBetween('date', [$start, $end])->get();

        return view('hr.leave.calendar', compact('leaves', 'holidays', 'month', 'year'));
    }

    // ══════════════════════════════════════════════════════════════════════
    // MANAGER: Team Leave Approval
    // ══════════════════════════════════════════════════════════════════════

    public function teamLeave(Request $request)
    {
        $user = Auth::user();
        $manager = $user->employee;
        if (!$manager) {
            return redirect()->route('user.dashboard')->with('error', 'No employee profile found.');
        }

        // Get direct report IDs
        $directReportIds = Employee::where('manager_id', $manager->id)->pluck('id');

        if ($directReportIds->isEmpty()) {
            return view('user.leave.team', [
                'applications' => collect(),
                'directReports' => collect(),
                'filterStatus' => $request->input('status', ''),
            ]);
        }

        $query = LeaveApplication::with(['employee', 'leaveType', 'approver'])
            ->whereIn('employee_id', $directReportIds)
            ->orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $applications = $query->paginate(20);
        $directReports = Employee::whereIn('id', $directReportIds)->orderBy('full_name')->get();

        return view('user.leave.team', compact('applications', 'directReports', 'manager'));
    }

    public function managerApprove(LeaveApplication $application)
    {
        $user = Auth::user();
        $manager = $user->employee;

        if (!$manager) {
            abort(403, 'No employee profile found.');
        }

        // Verify this employee reports to the current user
        $employee = $application->employee;
        if (!$employee || $employee->manager_id !== $manager->id) {
            abort(403, 'You can only approve leave for your direct reports.');
        }

        if ($application->status !== 'pending') {
            return back()->with('error', 'This application is no longer pending.');
        }

        $application->update([
            'manager_status' => 'approved',
            'manager_approved_by' => $manager->id,
            'manager_approved_at' => now(),
            'status' => 'approved',
            'approved_by' => $user->id,
            'approved_at' => now(),
        ]);

        // Update leave balance
        $balance = LeaveBalance::firstOrCreate(
            [
                'employee_id' => $application->employee_id,
                'leave_type_id' => $application->leave_type_id,
                'year' => $application->start_date->year,
            ],
            ['entitled' => 0, 'taken' => 0, 'carry_forward' => 0, 'adjustment' => 0]
        );
        $balance->increment('taken', $application->total_days);

        // Notify employee
        $application->load('leaveType');
        if ($employee->user?->work_email) {
            try {
                Mail::to($employee->user->work_email)->send(new LeaveApprovalNotifyMail(
                    $application, $employee, 'approved', $manager->full_name, 'manager'
                ));
            } catch (\Exception $e) {
                Log::warning('Failed to send manager leave approval email: ' . $e->getMessage());
            }
        }

        return back()->with('success', 'Leave approved for ' . $employee->full_name . '.');
    }

    public function managerReject(Request $request, LeaveApplication $application)
    {
        $user = Auth::user();
        $manager = $user->employee;

        if (!$manager) {
            abort(403, 'No employee profile found.');
        }

        $employee = $application->employee;
        if (!$employee || $employee->manager_id !== $manager->id) {
            abort(403, 'You can only reject leave for your direct reports.');
        }

        if ($application->status !== 'pending') {
            return back()->with('error', 'This application is no longer pending.');
        }

        $data = $request->validate([
            'manager_remarks' => 'required|string|max:500',
        ]);

        $application->update([
            'manager_status' => 'rejected',
            'manager_approved_by' => $manager->id,
            'manager_approved_at' => now(),
            'manager_remarks' => $data['manager_remarks'],
            'status' => 'rejected',
            'approved_by' => $user->id,
            'approved_at' => now(),
            'rejection_reason' => 'Rejected by Manager: ' . $data['manager_remarks'],
        ]);

        // Notify employee
        $application->load('leaveType');
        if ($employee->user?->work_email) {
            try {
                Mail::to($employee->user->work_email)->send(new LeaveApprovalNotifyMail(
                    $application, $employee, 'rejected', $manager->full_name, 'manager'
                ));
            } catch (\Exception $e) {
                Log::warning('Failed to send manager leave rejection email: ' . $e->getMessage());
            }
        }

        return back()->with('success', 'Leave rejected for ' . $employee->full_name . '.');
    }

    // ══════════════════════════════════════════════════════════════════════
    // ON-LEAVE THIS WEEK WIDGET (used by dashboards)
    // ══════════════════════════════════════════════════════════════════════

    public static function getOnLeaveThisWeek(?string $companyFilter = null): array
    {
        $weekStart = now()->startOfWeek();
        $weekEnd = now()->endOfWeek();

        $query = LeaveApplication::with(['employee', 'leaveType'])
            ->where('status', 'approved')
            ->where(function ($q) use ($weekStart, $weekEnd) {
                $q->whereBetween('start_date', [$weekStart, $weekEnd])
                    ->orWhereBetween('end_date', [$weekStart, $weekEnd])
                    ->orWhere(function ($q2) use ($weekStart, $weekEnd) {
                        $q2->where('start_date', '<=', $weekStart)
                            ->where('end_date', '>=', $weekEnd);
                    });
            });

        if ($companyFilter) {
            $query->whereHas('employee', fn($q) => $q->where('company', $companyFilter));
        }

        $leaves = $query->orderBy('start_date')->get();

        // Build day-by-day structure for the week
        $days = [];
        $current = $weekStart->copy();
        while ($current->lte($weekEnd)) {
            $dateStr = $current->toDateString();
            $dayLeaves = $leaves->filter(function ($leave) use ($current) {
                return $current->between($leave->start_date, $leave->end_date);
            });

            if ($dayLeaves->isNotEmpty() && !$current->isWeekend()) {
                $days[] = [
                    'date' => $current->copy(),
                    'day_name' => $current->format('l'),
                    'date_formatted' => $current->format('d M Y'),
                    'is_today' => $current->isToday(),
                    'leaves' => $dayLeaves->map(fn($l) => [
                        'employee_name' => $l->employee->preferred_name ?? $l->employee->full_name,
                        'leave_type' => $l->leaveType?->name ?? 'Leave',
                        'is_half_day' => $l->is_half_day,
                        'half_day_period' => $l->half_day_period,
                        'company' => $l->employee->company,
                    ])->values(),
                ];
            }
            $current->addDay();
        }

        return $days;
    }

    // ══════════════════════════════════════════════════════════════════════
    // PRIVATE HELPERS: Email Notifications
    // ══════════════════════════════════════════════════════════════════════

    private function notifyManager(LeaveApplication $application, Employee $employee): void
    {
        // Try manager_id relationship first, fallback to reporting_manager_email
        $managerEmail = null;

        if ($employee->manager_id) {
            $managerUser = $employee->manager?->user;
            $managerEmail = $managerUser?->work_email;
        }

        if (!$managerEmail && $employee->reporting_manager_email) {
            $managerEmail = $employee->reporting_manager_email;
        }

        if ($managerEmail) {
            try {
                Mail::to($managerEmail)->send(new LeaveApplicationNotifyMail($application, $employee, 'manager'));
            } catch (\Exception $e) {
                Log::warning('Failed to send leave notification to manager for employee #' . $employee->id . ': ' . $e->getMessage());
            }
        }
    }

    private function notifyHr(LeaveApplication $application, Employee $employee): void
    {
        // Find HR managers for the employee's company (or all HR managers)
        $hrUsers = \App\Models\User::whereIn('role', ['hr_manager', 'hr_executive'])
            ->where('is_active', true)
            ->get();

        foreach ($hrUsers as $hrUser) {
            if ($hrUser->work_email) {
                try {
                    Mail::to($hrUser->work_email)->send(new LeaveApplicationNotifyMail($application, $employee, 'hr'));
                } catch (\Exception $e) {
                    Log::warning('Failed to send leave notification to HR user #' . $hrUser->id . ': ' . $e->getMessage());
                }
            }
        }
    }
}
