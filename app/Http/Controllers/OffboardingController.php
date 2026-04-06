<?php

namespace App\Http\Controllers;

use App\Models\Company;
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
            $employee->load([
                'contracts.uploader',
                'educationHistories',
                'spouseDetails',
                'emergencyContacts',
                'childRegistration',
                'editLogs',
                'onboarding.personalDetail',
            ]);
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
            $employee->load([
                'contracts.uploader',
                'educationHistories',
                'spouseDetails',
                'emergencyContacts',
                'childRegistration',
                'editLogs',
                'onboarding.personalDetail',
            ]);
            $directAssets = \App\Models\AssetInventory::where('assigned_employee_id', $employee->id)
                ->whereIn('status', ['assigned', 'unavailable'])->orderBy('asset_type')->get();
            $aarf         = $employee->resolveAarf();
        } else {
            $directAssets = collect();
            $aarf         = null;
        }

        $managers  = User::whereIn('role', ['hr_manager','it_manager','superadmin','manager'])->orderBy('name')->with('employee')->get();
        $companies = Company::orderBy('name')->get(['name', 'address']);

        return view('hr.offboarding.edit', compact('offboarding', 'employee', 'directAssets', 'aarf', 'managers', 'companies'));
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
            'house_tel_no'           => 'nullable|string|max:50',
            'personal_email'         => 'nullable|email',
            'bank_account_number'    => 'nullable|string|max:100',
            'bank_name'              => 'nullable|string|max:100',
            'bank_name_other'        => 'nullable|string|max:100',
            'epf_no'                 => 'nullable|string|max:100',
            'income_tax_no'          => 'nullable|string|max:100',
            'socso_no'               => 'nullable|string|max:100',
            'is_disabled'            => 'nullable|boolean',
            'residential_address'    => 'nullable|string',
            // NRIC files
            'nric_files'             => 'nullable|array',
            'nric_files.*'           => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120',
            'nric_keep_paths'        => 'nullable|array',
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
            'last_salary_date'       => 'nullable|date',
            'company_email'          => 'nullable|email',
            'google_id'              => 'nullable|string|max:255',
            'reason'                 => 'nullable|string|max:500',
            'remarks'                => 'nullable|string',
            // Role
            'work_role'              => 'nullable|string|max:50',
            // Section F — Education
            'edu_qualification.*'    => 'nullable|string|max:255',
            'edu_institution.*'      => 'nullable|string|max:255',
            'edu_year.*'             => 'nullable|integer|min:1950|max:2099',
            'edu_certificate.*.*'    => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120',
            'edu_cert_keep.*.*'      => 'nullable|string',
            'edu_delete_ids'         => 'nullable|string',
        ]);

        // Merge bank_name_other when "Other" is selected
        if (($validated['bank_name'] ?? '') === 'Other' && !empty($validated['bank_name_other'])) {
            $validated['bank_name'] = $validated['bank_name_other'];
        }

        // Update offboarding record fields
        $offboarding->update([
            'full_name'     => $validated['full_name']     ?? $offboarding->full_name,
            'designation'   => $validated['designation']   ?? $offboarding->designation,
            'department'    => $validated['department']    ?? $offboarding->department,
            'company'       => $validated['company']       ?? $offboarding->company,
            'company_email' => $validated['company_email'] ?? $offboarding->company_email,
            'personal_email'=> $validated['personal_email'] ?? $offboarding->personal_email,
            'exit_date'     => $validated['exit_date']     ?? $offboarding->exit_date,
            'reason'        => $validated['reason']        ?? $offboarding->reason,
            'remarks'       => $validated['remarks']       ?? $offboarding->remarks,
        ]);

        // Update employee record if linked
        if ($offboarding->employee) {
            $emp = $offboarding->employee;

            // NRIC file handling (sentinel pattern)
            $existingNric  = $emp->nric_file_paths ?? ($emp->nric_file_path ? [$emp->nric_file_path] : []);
            $keepSubmitted = $request->has('nric_keep_submitted');
            if ($keepSubmitted) {
                $keptNric     = (array) $request->input('nric_keep_paths', []);
                $existingNric = array_values(array_intersect($existingNric, $keptNric));
            }
            $newNricPaths = [];
            if ($request->hasFile('nric_files')) {
                foreach ($request->file('nric_files') as $file) {
                    $newNricPaths[] = $file->store('nric_documents', 'local');
                }
            }
            $allNric = array_merge($existingNric, $newNricPaths);

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
                'house_tel_no'            => $validated['house_tel_no'] ?? null,
                'personal_email'          => $validated['personal_email'] ?? null,
                'bank_account_number'     => $validated['bank_account_number'] ?? null,
                'bank_name'               => $validated['bank_name'] ?? null,
                'epf_no'                  => $validated['epf_no'] ?? null,
                'income_tax_no'           => $validated['income_tax_no'] ?? null,
                'socso_no'                => $validated['socso_no'] ?? null,
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
            if ($u->isHrManager()) {
                $empData['last_salary_date'] = $validated['last_salary_date'] ?? null;
            }

            // is_disabled handled separately (falsy 0/false should not be filtered out)
            if (isset($validated['is_disabled'])) {
                $empData['is_disabled'] = (bool) $validated['is_disabled'];
            }

            // Apply NRIC changes
            if (!empty($allNric)) {
                $empData['nric_file_paths'] = $allNric;
                $empData['nric_file_path']  = $allNric[0];
            } elseif ($keepSubmitted) {
                $empData['nric_file_paths'] = null;
                $empData['nric_file_path']  = null;
            }

            if (!empty($empData)) {
                $emp->update($empData);
            }

            // ── Sync employee changes back to linked onboarding ───────────
            $emp->refresh();
            if ($emp->onboarding_id) {
                $ob = \App\Models\Onboarding::with(['personalDetail', 'workDetail'])->find($emp->onboarding_id);
                if ($ob?->personalDetail) {
                    $ob->personalDetail->update([
                        'full_name'               => $emp->full_name,
                        'preferred_name'          => $emp->preferred_name,
                        'official_document_id'    => $emp->official_document_id,
                        'date_of_birth'           => $emp->date_of_birth,
                        'sex'                     => $emp->sex,
                        'marital_status'          => $emp->marital_status,
                        'religion'                => $emp->religion,
                        'race'                    => $emp->race,
                        'is_disabled'             => $emp->is_disabled,
                        'residential_address'     => $emp->residential_address,
                        'personal_contact_number' => $emp->personal_contact_number,
                        'house_tel_no'            => $emp->house_tel_no,
                        'personal_email'          => $emp->personal_email,
                        'bank_account_number'     => $emp->bank_account_number,
                        'bank_name'               => $emp->bank_name,
                        'epf_no'                  => $emp->epf_no,
                        'income_tax_no'           => $emp->income_tax_no,
                        'socso_no'                => $emp->socso_no,
                    ]);
                }
                if ($ob?->workDetail) {
                    $ob->workDetail->update([
                        'designation'       => $emp->designation,
                        'department'        => $emp->department,
                        'company'           => $emp->company,
                        'office_location'   => $emp->office_location,
                        'reporting_manager' => $emp->reporting_manager,
                        'company_email'     => $emp->company_email,
                        'google_id'         => $emp->google_id,
                        'employment_type'   => $emp->employment_type,
                        'start_date'        => $emp->start_date,
                        'exit_date'         => $emp->exit_date,
                    ]);
                }
            }
        }

        // ── Section F — Education History ──────────────────────────────────
        $employee = $offboarding->employee;
        if ($employee && $request->has('edu_qualification')) {
            $deleteIds = array_filter(explode(',', $request->input('edu_delete_ids', '')));
            if (!empty($deleteIds)) {
                \App\Models\EmployeeEducationHistory::where('employee_id', $employee->id)
                    ->whereIn('id', $deleteIds)->delete();
            }

            foreach ($request->input('edu_qualification', []) as $i => $qual) {
                if (empty(trim((string)$qual))) continue;

                $existingId = $request->input("edu_id.{$i}");
                $keepPaths  = $request->input("edu_cert_keep.{$i}", null);

                // FIX: use the sentinel field to distinguish "user submitted cert section
                // with zero keeps (all deleted)" from "edit panel was never opened (keep all)".
                if ($existingId && $request->has("edu_cert_keep_submitted.{$i}")) {
                    $row          = \App\Models\EmployeeEducationHistory::where('employee_id', $employee->id)->find($existingId);
                    $allExisting  = $row ? ($row->certificate_paths ?? ($row->certificate_path ? [$row->certificate_path] : [])) : [];
                    $keptExisting = array_values(array_intersect($allExisting, (array)$keepPaths));
                } elseif ($existingId) {
                    $row          = \App\Models\EmployeeEducationHistory::where('employee_id', $employee->id)->find($existingId);
                    $keptExisting = $row ? ($row->certificate_paths ?? ($row->certificate_path ? [$row->certificate_path] : [])) : [];
                } else {
                    $keptExisting = [];
                }

                $newCertPaths = [];
                if ($request->hasFile("edu_certificate.{$i}")) {
                    foreach ((array)$request->file("edu_certificate.{$i}") as $certFile) {
                        if ($certFile && $certFile->isValid()) {
                            $newCertPaths[] = $certFile->store('education_certificates', 'local');
                        }
                    }
                }

                $mergedCerts = array_values(array_merge($keptExisting, $newCertPaths));
                $mergedCerts = array_slice($mergedCerts, 0, 5);

                if ($existingId) {
                    if (!isset($row)) {
                        $row = \App\Models\EmployeeEducationHistory::where('employee_id', $employee->id)->find($existingId);
                    }
                    if ($row) {
                        $row->update([
                            'qualification'     => $qual,
                            'institution'       => $request->input("edu_institution.{$i}"),
                            'year_graduated'    => $request->input("edu_year.{$i}"),
                            'certificate_path'  => $mergedCerts[0] ?? null,
                            'certificate_paths' => !empty($mergedCerts) ? $mergedCerts : null,
                        ]);
                    }
                } else {
                    \App\Models\EmployeeEducationHistory::create([
                        'employee_id'       => $employee->id,
                        'qualification'     => $qual,
                        'institution'       => $request->input("edu_institution.{$i}"),
                        'year_graduated'    => $request->input("edu_year.{$i}"),
                        'certificate_path'  => $mergedCerts[0] ?? null,
                        'certificate_paths' => !empty($mergedCerts) ? $mergedCerts : null,
                    ]);
                }
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
            $employee->load([
                'educationHistories',
                'spouseDetails',
                'emergencyContacts',
                'childRegistration',
            ]);
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