<?php

namespace App\Http\Controllers;

use App\Models\Offboarding;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class OffboardingController extends Controller
{
    // ── Shared: determine month/year filtered query ──────────────────────
    private function baseQuery(Request $request)
    {
        $month = $request->input('month');
        $year  = $request->input('year');

        $query = Offboarding::with(['employee', 'picUser'])
            ->whereNotNull('exit_date');

        if ($month && $year) {
            // User has selected a specific month/year filter
            $query->whereMonth('exit_date', $month)->whereYear('exit_date', $year);
        } else {
            // Default: show upcoming exits (today onwards) ordered soonest first
            $query->where('exit_date', '>=', now()->toDateString());
            $month = now()->month;
            $year  = now()->year;
        }

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(fn($q) =>
                $q->where('full_name', 'like', "%{$s}%")
                  ->orWhere('company_email', 'like', "%{$s}%")
                  ->orWhere('company', 'like', "%{$s}%")
                  ->orWhere('department', 'like', "%{$s}%")
            );
        }
        if ($request->filled('company'))    $query->where('company', 'like', "%{$request->company}%");
        if ($request->filled('department')) $query->where('department', 'like', "%{$request->department}%");

        return [$query, $month, $year];
    }

    private function sharedCompact(): array
    {
        return [
            'months' => ['1'=>'January','2'=>'February','3'=>'March','4'=>'April',
                         '5'=>'May','6'=>'June','7'=>'July','8'=>'August',
                         '9'=>'September','10'=>'October','11'=>'November','12'=>'December'],
            'years'  => range(now()->year - 2, now()->year + 2),
        ];
    }

    // ── HR: offboarding index ────────────────────────────────────────────
    public function hrIndex(Request $request)
    {
        $u = Auth::user();
        if (!$u->isHr() && !$u->isSuperadmin() && !$u->isSystemAdmin()) abort(403);

        [$query, $month, $year] = $this->baseQuery($request);
        $offboardings = $query->orderBy('exit_date')->paginate(20)->withQueryString();
        $companies    = Offboarding::distinct()->pluck('company')->filter()->sort()->values();

        $itStaff = User::whereIn('role', ['it_manager', 'it_executive', 'it_intern'])
            ->where('is_active', true)
            ->whereDoesntHave('employee', fn($q) => $q->where(function ($q2) {
                $q2->whereNotNull('active_until')
                   ->orWhere(fn($q3) => $q3->whereNotNull('exit_date')->where('exit_date', '<', now()->toDateString()));
            }))
            ->orderBy('name')
            ->with('employee')
            ->get();

        return view('hr.offboarding.index', array_merge(
            compact('offboardings', 'companies', 'month', 'year', 'itStaff'),
            $this->sharedCompact()
        ));
    }

    // ── HR: show offboarding detail ──────────────────────────────────────
    public function hrShow(Offboarding $offboarding)
    {
        $u = Auth::user();
        if (!$u->isHr() && !$u->isSuperadmin() && !$u->isSystemAdmin()) abort(403);

        $offboarding->load(['employee.contracts', 'picUser']);
        $employee = $offboarding->employee;

        if ($employee) {
            $employee->load(['contracts.uploader']);
            $directAssets    = \App\Models\AssetInventory::where('assigned_employee_id', $employee->id)
                ->whereIn('status', ['assigned', 'unavailable'])->orderBy('asset_type')->get();
            $availableAssets = collect();
            $aarf            = $employee->resolveAarf();
        } else {
            $directAssets    = collect();
            $availableAssets = collect();
            $aarf            = null;
        }

        return view('hr.offboarding.show', compact('offboarding', 'employee', 'directAssets', 'availableAssets', 'aarf'));
    }

    // ── HR Manager: edit offboarding ─────────────────────────────────────
    public function hrEdit(Offboarding $offboarding)
    {
        $u = Auth::user();
        if (!$u->isHrManager() && !$u->isSuperadmin()) abort(403);

        $offboarding->load(['employee.contracts', 'picUser']);
        $employee = $offboarding->employee;

        if ($employee) {
            $employee->load(['contracts.uploader']);
            $directAssets = \App\Models\AssetInventory::where('assigned_employee_id', $employee->id)
                ->whereIn('status', ['assigned', 'unavailable'])->orderBy('asset_type')->get();
            $aarf         = $employee->resolveAarf();
        } else {
            $directAssets = collect();
            $aarf         = null;
        }

        $managers = User::whereIn('role', ['hr_manager','it_manager','superadmin'])->orderBy('name')->get();

        return view('hr.offboarding.edit', compact('offboarding', 'employee', 'directAssets', 'aarf', 'managers'));
    }

    // ── HR Manager: update offboarding ───────────────────────────────────
    public function hrUpdate(Request $request, Offboarding $offboarding)
    {
        $u = Auth::user();
        if (!$u->isHrManager() && !$u->isSuperadmin()) abort(403);

        $validated = $request->validate([
            // Personal
            'full_name'              => 'nullable|string|max:255',
            'preferred_name'         => 'nullable|string|max:255',
            'official_document_id'   => 'nullable|string|max:255',
            'date_of_birth'          => 'nullable|date',
            'sex'                    => 'nullable|in:male,female',
            'marital_status'         => 'nullable|in:single,married,divorced,widowed',
            'religion'               => 'nullable|string|max:100',
            'race'                   => 'nullable|string|max:100',
            'personal_contact_number'=> 'nullable|string|max:50',
            'personal_email'         => 'nullable|email',
            'bank_account_number'    => 'nullable|string|max:100',
            'residential_address'    => 'nullable|string',
            // Work
            'employment_type'        => 'nullable|in:permanent,intern,contract',
            'employment_status'      => 'nullable|in:active,resigned,terminated,contract_ended',
            'designation'            => 'nullable|string|max:255',
            'department'             => 'nullable|string|max:255',
            'company'                => 'nullable|string|max:255',
            'office_location'        => 'nullable|string|max:255',
            'reporting_manager'      => 'nullable|string|max:255',
            'start_date'             => 'nullable|date',
            'exit_date'              => 'nullable|date',
            'company_email'          => 'nullable|email',
            'google_id'              => 'nullable|string|max:255',
            'reason'                 => 'nullable|string|max:500',
            'remarks'                => 'nullable|string',
            // Role
            'work_role'              => 'nullable|string|max:50',
        ]);

        // Update offboarding record fields
        $offboarding->update([
            'full_name'    => $validated['full_name']    ?? $offboarding->full_name,
            'designation'  => $validated['designation']  ?? $offboarding->designation,
            'department'   => $validated['department']   ?? $offboarding->department,
            'company'      => $validated['company']      ?? $offboarding->company,
            'company_email'=> $validated['company_email']?? $offboarding->company_email,
            'personal_email'=> $validated['personal_email'] ?? $offboarding->personal_email,
            'exit_date'    => $validated['exit_date']    ?? $offboarding->exit_date,
            'reason'       => $validated['reason']       ?? $offboarding->reason,
            'remarks'      => $validated['remarks']      ?? $offboarding->remarks,
        ]);

        // Update employee record if linked
        if ($offboarding->employee) {
            $emp = $offboarding->employee;
            $empData = array_filter([
                'full_name'               => $validated['full_name'] ?? null,
                'preferred_name'          => $validated['preferred_name'] ?? null,
                'official_document_id'    => $validated['official_document_id'] ?? null,
                'date_of_birth'           => $validated['date_of_birth'] ?? null,
                'sex'                     => $validated['sex'] ?? null,
                'marital_status'          => $validated['marital_status'] ?? null,
                'religion'                => $validated['religion'] ?? null,
                'race'                    => $validated['race'] ?? null,
                'personal_contact_number' => $validated['personal_contact_number'] ?? null,
                'personal_email'          => $validated['personal_email'] ?? null,
                'bank_account_number'     => $validated['bank_account_number'] ?? null,
                'residential_address'     => $validated['residential_address'] ?? null,
                'employment_type'         => $validated['employment_type'] ?? null,
                'employment_status'       => $validated['employment_status'] ?? null,
                'designation'             => $validated['designation'] ?? null,
                'department'              => $validated['department'] ?? null,
                'company'                 => $validated['company'] ?? null,
                'office_location'         => $validated['office_location'] ?? null,
                'reporting_manager'       => $validated['reporting_manager'] ?? null,
                'start_date'              => $validated['start_date'] ?? null,
                'exit_date'               => $validated['exit_date'] ?? null,
                'company_email'           => $validated['company_email'] ?? null,
                'google_id'               => $validated['google_id'] ?? null,
                'work_role'               => $validated['work_role'] ?? null,
            ], fn($v) => $v !== null);

            if (!empty($empData)) {
                $emp->update($empData);
            }
        }

        return redirect()->route('hr.offboarding.show', $offboarding)
            ->with('success', 'Offboarding record updated successfully.');
    }

    // ── IT: offboarding index ────────────────────────────────────────────
    public function itIndex(Request $request)
    {
        $u = Auth::user();
        if (!$u->isIt() && !$u->isSuperadmin()) abort(403);

        [$query, $month, $year] = $this->baseQuery($request);
        $offboardings = $query->orderBy('exit_date')->paginate(20)->withQueryString();
        $companies    = Offboarding::distinct()->pluck('company')->filter()->sort()->values();

        // IT staff for PIC dropdown — include IT Manager, executive, intern
        // Exclude: offboarded (active_until set) OR exit date has already passed
        $itStaff = User::whereIn('role', ['it_manager', 'it_executive', 'it_intern'])
            ->where('is_active', true)
            ->whereDoesntHave('employee', fn($q) => $q->where(function ($q2) {
                $q2->whereNotNull('active_until')
                   ->orWhere(fn($q3) => $q3->whereNotNull('exit_date')->where('exit_date', '<', now()->toDateString()));
            }))
            ->orderBy('name')
            ->with('employee')
            ->get();

        return view('it.offboarding', array_merge(
            compact('offboardings', 'companies', 'month', 'year', 'itStaff'),
            $this->sharedCompact()
        ));
    }

    // ── IT: view offboarding detail (read-only) ──────────────────────────
    public function itShow(Offboarding $offboarding)
    {
        $u = Auth::user();
        if (!$u->isIt() && !$u->isSuperadmin()) abort(403);

        $offboarding->load(['employee', 'picUser']);
        $employee = $offboarding->employee;

        if ($employee) {
            $directAssets = \App\Models\AssetInventory::where('assigned_employee_id', $employee->id)
                ->whereIn('status', ['assigned', 'unavailable'])->orderBy('asset_type')->get();
            $aarf         = $employee->resolveAarf();
        } else {
            $directAssets = collect();
            $aarf         = null;
        }

        return view('it.offboarding-show', compact('offboarding', 'employee', 'directAssets', 'aarf'));
    }

    // ── IT Manager: assign PIC ───────────────────────────────────────────
    public function assignPic(Request $request, Offboarding $offboarding)
    {
        $u = Auth::user();
        if (!$u->isItManager() && !$u->isSuperadmin()) abort(403);

        $request->validate(['assigned_pic_user_id' => 'required|exists:users,id']);
        $offboarding->update(['assigned_pic_user_id' => $request->assigned_pic_user_id]);

        return back()->with('success', 'PIC assigned successfully.');
    }

    // ── Shared: update status fields ─────────────────────────────────────
    public function updateStatus(Request $request, Offboarding $offboarding)
    {
        $u = Auth::user();
        if (!$u->isIt() && !$u->isHr() && !$u->isSuperadmin()) abort(403);

        $request->validate([
            'field'  => 'required|in:calendar_reminder_status,exiting_email_status,aarf_status,notice_email_status,reminder_email_status,sendoff_email_status',
            'status' => 'required|string',
        ]);

        $offboarding->update([$request->field => $request->status]);
        return back()->with('success', 'Status updated.');
    }
}