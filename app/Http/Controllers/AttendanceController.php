<?php

namespace App\Http\Controllers;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\OvertimeRequest;
use App\Models\WorkSchedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AttendanceController extends Controller
{
    // ── HR: Work Schedules ─────────────────────────────────────────────
    public function schedules()
    {
        $schedules = WorkSchedule::orderBy('name')->get();
        return view('hr.attendance.schedules', compact('schedules'));
    }

    public function storeSchedule(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'company' => 'nullable|string|max:255',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i|after:start_time',
            'break_start' => 'nullable|date_format:H:i',
            'break_end' => 'nullable|date_format:H:i',
            'work_hours_per_day' => 'required|numeric|min:1|max:24',
            'working_days' => 'required|array|min:1',
            'working_days.*' => 'integer|min:0|max:6',
        ]);

        $data['is_default'] = $request->boolean('is_default');

        if ($data['is_default']) {
            WorkSchedule::where('company', $data['company'] ?? null)
                ->where('is_default', true)
                ->update(['is_default' => false]);
        }

        WorkSchedule::create($data);

        return back()->with('success', 'Work schedule created.');
    }

    // ── HR: Attendance Records Overview ────────────────────────────────
    public function index(Request $request)
    {
        $date = $request->input('date', now()->format('Y-m-d'));
        $month = $request->input('month', now()->format('Y-m'));

        $query = AttendanceRecord::with('employee')
            ->orderByDesc('date')
            ->orderBy('clock_in');

        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->employee_id);
        }

        if ($request->input('view') === 'daily') {
            $query->whereDate('date', $date);
        } else {
            $start = \Carbon\Carbon::parse($month . '-01')->startOfMonth();
            $end = $start->copy()->endOfMonth();
            $query->whereBetween('date', [$start, $end]);
        }

        $records = $query->paginate(30);
        $employees = Employee::orderBy('full_name')->get();

        return view('hr.attendance.index', compact('records', 'employees', 'date', 'month'));
    }

    // ── HR: Overtime Requests ──────────────────────────────────────────
    public function overtimeRequests(Request $request)
    {
        $query = OvertimeRequest::with(['employee', 'approver'])
            ->orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $requests = $query->paginate(20);

        return view('hr.attendance.overtime', compact('requests'));
    }

    public function approveOvertime(OvertimeRequest $overtime)
    {
        if ($overtime->status !== 'pending') {
            return back()->with('error', 'Request already processed.');
        }

        $overtime->update([
            'status' => 'approved',
            'approved_by' => Auth::id(),
            'approved_at' => now(),
        ]);

        // Update attendance record overtime hours
        $record = AttendanceRecord::where('employee_id', $overtime->employee_id)
            ->whereDate('date', $overtime->date)
            ->first();
        if ($record) {
            $record->update(['overtime_hours' => $overtime->hours]);
        }

        return back()->with('success', 'Overtime approved.');
    }

    public function rejectOvertime(Request $request, OvertimeRequest $overtime)
    {
        if ($overtime->status !== 'pending') {
            return back()->with('error', 'Request already processed.');
        }

        $data = $request->validate([
            'rejection_reason' => 'required|string|max:500',
        ]);

        $overtime->update([
            'status' => 'rejected',
            'approved_by' => Auth::id(),
            'approved_at' => now(),
            'rejection_reason' => $data['rejection_reason'],
        ]);

        return back()->with('success', 'Overtime rejected.');
    }

    // ── Employee: Clock In/Out ─────────────────────────────────────────
    public function myAttendance(Request $request)
    {
        $employee = Auth::user()->employee;
        if (!$employee) {
            return redirect()->route(Auth::user()->isHr() || Auth::user()->isSuperadmin() || Auth::user()->isSystemAdmin() ? 'hr.dashboard' : (Auth::user()->isIt() ? 'it.dashboard' : 'user.dashboard'))->with('error', 'No employee profile found.');
        }

        $month = $request->input('month', now()->format('Y-m'));
        $start = \Carbon\Carbon::parse($month . '-01')->startOfMonth();
        $end = $start->copy()->endOfMonth();

        $records = AttendanceRecord::where('employee_id', $employee->id)
            ->whereBetween('date', [$start, $end])
            ->orderByDesc('date')
            ->get();

        $todayRecord = AttendanceRecord::where('employee_id', $employee->id)
            ->whereDate('date', today())
            ->first();

        $overtimeRequests = OvertimeRequest::where('employee_id', $employee->id)
            ->orderByDesc('created_at')
            ->take(10)
            ->get();

        $monthlyHours = $records->sum('work_hours');
        $presentDays = $records->whereIn('status', ['present', 'late'])->count();

        return view('user.attendance.index', compact('records', 'todayRecord', 'overtimeRequests', 'month', 'employee', 'monthlyHours', 'presentDays'));
    }

    public function clockIn(Request $request)
    {
        $employee = Auth::user()->employee;
        if (!$employee) {
            return back()->with('error', 'No employee profile found.');
        }

        $today = now()->toDateString();
        $existing = AttendanceRecord::where('employee_id', $employee->id)
            ->whereDate('date', $today)
            ->first();

        if ($existing && $existing->clock_in) {
            return back()->with('error', 'Already clocked in today.');
        }

        // Determine schedule and lateness
        $schedule = WorkSchedule::where('is_default', true)->first();
        $status = 'present';
        if ($schedule) {
            $scheduledStart = \Carbon\Carbon::parse($today . ' ' . $schedule->start_time);
            if (now()->gt($scheduledStart->addMinutes(15))) {
                $status = 'late';
            }
        }

        AttendanceRecord::updateOrCreate(
            ['employee_id' => $employee->id, 'date' => $today],
            [
                'clock_in' => now(),
                'status' => $status,
                'clock_in_ip' => $request->ip(),
                'work_schedule_id' => $schedule?->id,
            ]
        );

        return back()->with('success', 'Clocked in at ' . now()->format('h:i A'));
    }

    public function clockOut(Request $request)
    {
        $employee = Auth::user()->employee;
        if (!$employee) {
            return back()->with('error', 'No employee profile found.');
        }

        $record = AttendanceRecord::where('employee_id', $employee->id)
            ->whereDate('date', today())
            ->first();

        if (!$record || !$record->clock_in) {
            return back()->with('error', 'No clock-in found for today.');
        }

        if ($record->clock_out) {
            return back()->with('error', 'Already clocked out today.');
        }

        $clockIn = \Carbon\Carbon::parse($record->clock_in);
        $clockOut = now();
        $workHours = $clockIn->diffInMinutes($clockOut) / 60;

        // Subtract break
        $breakDuration = 0;
        if ($record->workSchedule && $record->workSchedule->break_start && $record->workSchedule->break_end) {
            $breakStart = \Carbon\Carbon::parse($record->workSchedule->break_start);
            $breakEnd = \Carbon\Carbon::parse($record->workSchedule->break_end);
            $breakDuration = $breakStart->diffInMinutes($breakEnd) / 60;
        }
        $workHours = max(0, $workHours - $breakDuration);

        // Calculate overtime
        $overtimeHours = 0;
        if ($record->workSchedule) {
            $scheduledHours = (float) $record->workSchedule->work_hours_per_day;
            if ($workHours > $scheduledHours) {
                $overtimeHours = $workHours - $scheduledHours;
            }
        }

        $record->update([
            'clock_out' => $clockOut,
            'clock_out_ip' => $request->ip(),
            'work_hours' => round($workHours, 2),
            'break_duration' => round($breakDuration, 2),
            'overtime_hours' => round($overtimeHours, 2),
        ]);

        return back()->with('success', 'Clocked out at ' . $clockOut->format('h:i A') . '. Worked ' . round($workHours, 1) . ' hours.');
    }

    // ── Employee: Submit Overtime Request ──────────────────────────────
    public function submitOvertime(Request $request)
    {
        $employee = Auth::user()->employee;
        if (!$employee) {
            return back()->with('error', 'No employee profile found.');
        }

        $data = $request->validate([
            'date' => 'required|date|before_or_equal:today',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i|after:start_time',
            'reason' => 'nullable|string|max:500',
        ]);

        $start = \Carbon\Carbon::parse($data['start_time']);
        $end = \Carbon\Carbon::parse($data['end_time']);
        $hours = $start->diffInMinutes($end) / 60;

        OvertimeRequest::create([
            'employee_id' => $employee->id,
            'date' => $data['date'],
            'start_time' => $data['start_time'],
            'end_time' => $data['end_time'],
            'hours' => round($hours, 2),
            'multiplier' => 1.5,
            'reason' => $data['reason'] ?? null,
            'status' => 'pending',
        ]);

        return back()->with('success', 'Overtime request submitted.');
    }

    // ── HR: Attendance Summary Report ──────────────────────────────────
    public function report(Request $request)
    {
        $month = $request->input('month', now()->format('Y-m'));
        $start = \Carbon\Carbon::parse($month . '-01')->startOfMonth();
        $end = $start->copy()->endOfMonth();

        $summary = AttendanceRecord::whereBetween('date', [$start, $end])
            ->selectRaw('employee_id,
                COUNT(*) as total_days,
                SUM(CASE WHEN status = "present" THEN 1 ELSE 0 END) as present_days,
                SUM(CASE WHEN status = "late" THEN 1 ELSE 0 END) as late_days,
                SUM(CASE WHEN status = "absent" THEN 1 ELSE 0 END) as absent_days,
                SUM(CASE WHEN status = "on_leave" THEN 1 ELSE 0 END) as leave_days,
                SUM(COALESCE(work_hours, 0)) as total_work_hours,
                SUM(COALESCE(overtime_hours, 0)) as total_overtime_hours')
            ->groupBy('employee_id')
            ->get()
            ->load('employee');

        return view('hr.attendance.report', compact('summary', 'month'));
    }
}
