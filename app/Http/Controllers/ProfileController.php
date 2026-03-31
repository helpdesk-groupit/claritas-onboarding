<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use App\Models\Employee;
use App\Models\EmployeeEducationHistory;
use App\Models\EmployeeSpouseDetail;
use App\Models\EmployeeEmergencyContact;
use App\Models\EmployeeChildRegistration;
use App\Models\Onboarding;

class ProfileController extends Controller
{
    public function show()
    {
        $user     = Auth::user();
        $employee = $user->employee?->load(
            'onboarding.personalDetail', 'onboarding.workDetail',
            'onboarding.assetAssignments.asset', 'onboarding.aarf',
            'educationHistories', 'spouseDetails', 'emergencyContacts', 'childRegistration',
            'editLogs'
        );

        if ($employee && !$employee->full_name && $employee->onboarding) {
            $employee->populateFromOnboarding();
            $employee->refresh();
        }

        if (!$employee) {
            $onboarding = \App\Models\Onboarding::whereHas('workDetail', function($q) use ($user) {
                $q->where('company_email', $user->work_email);
            })->with(['personalDetail','workDetail','assetAssignments.asset','aarf'])->first();

            if ($onboarding) {
                $employee = Employee::firstOrCreate(
                    ['user_id' => $user->id],
                    ['active_from' => now()->toDateString(), 'onboarding_id' => $onboarding->id]
                );
                $employee->populateFromOnboarding();
                $employee->load(
                    'onboarding.personalDetail','onboarding.workDetail',
                    'onboarding.assetAssignments.asset','onboarding.aarf',
                    'educationHistories','spouseDetails','emergencyContacts','childRegistration'
                );
            }
        }

        $contracts = $employee?->contracts()->latest()->get() ?? collect();

        $aarf = null;
        if ($employee) {
            $aarf = $employee->onboarding?->aarf
                 ?? \App\Models\Aarf::where('employee_id', $employee->id)->first();
        }

        $allAssets = collect();
        if ($employee) {
            $obAssignments = ($employee->onboarding?->assetAssignments ?? collect())->where('status', 'assigned');
            $allAssets = $allAssets->merge($obAssignments);
            $directAssignments = \App\Models\AssetAssignment::with('asset')
                ->where('employee_id', $employee->id)->where('status', 'assigned')->get();
            $existingIds = $allAssets->pluck('id')->filter()->toArray();
            foreach ($directAssignments as $da) {
                if (!in_array($da->id, $existingIds)) $allAssets->push($da);
            }
        }

        // Determine if there is an unacknowledged consent log requiring re-acknowledgement
        $editLogs = $employee?->editLogs ?? collect();
        $pendingConsentLog = $editLogs->first(fn($l) =>
            $l->consent_required && !$l->acknowledged_at && !$l->isTokenExpired()
        );

        return view('user.profile', compact('user', 'employee', 'contracts', 'aarf', 'allAssets', 'editLogs', 'pendingConsentLog'));
    }

    // ── Update personal biodata (Section A) ──────────────────────────────
    public function updateBiodata(Request $request)
    {
        $validated = $request->validate([
            'full_name'               => 'required|string|max:255',
            'preferred_name'          => 'nullable|string|max:100',
            'official_document_id'    => 'required|string|max:50',
            'date_of_birth'           => 'required|date',
            'sex'                     => 'required|in:male,female',
            'marital_status'          => 'required|in:single,married,divorced,widowed',
            'religion'                => 'required|string|max:100',
            'race'                    => 'required|string|max:100',
            'is_disabled'             => 'nullable|boolean',
            'personal_contact_number' => 'required|string|max:20',
            'house_tel_no'            => 'nullable|string|max:20',
            'personal_email'          => 'required|email',
            'bank_account_number'     => 'required|string|max:50',
            'bank_name'               => 'nullable|string|max:100',
            'bank_name_other'         => 'nullable|string|max:100',
            'epf_no'                  => 'nullable|string|max:50',
            'income_tax_no'           => 'nullable|string|max:50',
            'socso_no'                => 'nullable|string|max:50',
            'residential_address'     => 'required|string',
            'nric_files'              => 'nullable|array|max:5',
            'nric_files.*'            => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120',
        ]);

        $bankName = (in_array($validated['bank_name'] ?? '', ['Other','other']))
            ? ($validated['bank_name_other'] ?? null)
            : ($validated['bank_name'] ?? null);
        unset($validated['bank_name_other']);
        $validated['bank_name']   = $bankName;
        $validated['is_disabled'] = $request->boolean('is_disabled');

        // Handle NRIC file uploads
        if ($request->hasFile('nric_files')) {
            $nricPaths = [];
            foreach ($request->file('nric_files') as $file) {
                if ($file && $file->isValid()) {
                    $nricPaths[] = $file->store('nric_documents', 'public');
                }
            }
            if (!empty($nricPaths)) {
                $employee = $this->getOrCreateEmployee();
                $existing = $employee->nric_file_paths ?? [];
                $validated['nric_file_paths'] = array_merge($existing, $nricPaths);
                $validated['nric_file_path']  = $validated['nric_file_paths'][0];
            }
        }
        unset($validated['nric_files']);

        $employee = $this->getOrCreateEmployee();
        $employee->update($validated);

        $user = Auth::user();
        if ($user->name !== $validated['full_name']) {
            $user->update(['name' => $validated['full_name']]);
        }

        return back()->with('success', 'Personal information updated.');
    }

    // ── Update work data ──────────────────────────────────────────────────
    public function updateWork(Request $request)
    {
        $validated = $request->validate([
            'designation'       => 'required|string|max:255',
            'department'        => 'nullable|string|max:255',
            'company'           => 'required|string|max:255',
            'office_location'   => 'required|string|max:255',
            'reporting_manager' => 'required|string|max:255',
            'company_email'     => 'nullable|email',
            'start_date'        => 'nullable|date',
            'employment_type'   => 'nullable|in:permanent,intern,contract',
        ]);

        $user     = Auth::user();
        $employee = $this->getOrCreateEmployee();
        $employee->update($validated);

        if (!empty($validated['company_email']) && $validated['company_email'] !== $user->work_email) {
            $taken = \App\Models\User::where('work_email', $validated['company_email'])
                ->where('id', '!=', $user->id)->exists();
            if (!$taken) {
                $user->update(['work_email' => $validated['company_email']]);
                Auth::setUser($user->fresh());
            }
        }

        return back()->with('success', 'Work information updated.');
    }

    // ── Update education histories ────────────────────────────────────────
    public function updateEducation(Request $request)
    {
        $request->validate([
            'edu_qualification.*'  => 'nullable|string|max:255',
            'edu_institution.*'    => 'nullable|string|max:255',
            'edu_year.*'           => 'nullable|integer|min:1950|max:2099',
            'edu_experience_total' => 'nullable|string|max:10',
            'edu_certificate.*'    => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120',
            'edu_delete_ids'       => 'nullable|string',
        ]);

        $employee = $this->getOrCreateEmployee();

        // Delete flagged rows
        $deleteIds = array_filter(explode(',', $request->input('edu_delete_ids', '')));
        if (!empty($deleteIds)) {
            EmployeeEducationHistory::where('employee_id', $employee->id)
                ->whereIn('id', $deleteIds)->delete();
        }

        // Total experience stored on first row only
        $expTotal = $request->input('edu_experience_total');

        foreach ($request->input('edu_qualification', []) as $i => $qual) {
            if (empty(trim((string)$qual))) continue;

            $certPath = null;
            if ($request->hasFile("edu_certificate.{$i}") && $request->file("edu_certificate.{$i}")->isValid()) {
                $certPath = $request->file("edu_certificate.{$i}")->store('education_certificates', 'public');
            }

            $yearsExp = ($i === 0) ? $expTotal : null;
            $existingId = $request->input("edu_id.{$i}");
            if ($existingId) {
                $row = EmployeeEducationHistory::where('employee_id', $employee->id)->find($existingId);
                if ($row) {
                    $row->update([
                        'qualification'    => $qual,
                        'institution'      => $request->input("edu_institution.{$i}"),
                        'year_graduated'   => $request->input("edu_year.{$i}"),
                        'years_experience' => $yearsExp,
                    ] + ($certPath ? ['certificate_path' => $certPath] : []));
                }
            } else {
                EmployeeEducationHistory::create([
                    'employee_id'      => $employee->id,
                    'qualification'    => $qual,
                    'institution'      => $request->input("edu_institution.{$i}"),
                    'year_graduated'   => $request->input("edu_year.{$i}"),
                    'years_experience' => $yearsExp,
                    'certificate_path' => $certPath,
                ]);
            }
        }

        return back()->with('success', 'Education history updated.');
    }

    // ── Update spouse details ─────────────────────────────────────────────
    public function updateSpouse(Request $request)
    {
        $validated = $request->validate([
            'spouse_name'          => 'required|string|max:255',
            'spouse_address'       => 'nullable|string',
            'spouse_nric_no'       => 'nullable|string|max:50',
            'spouse_tel_no'        => 'nullable|string|max:30',
            'spouse_occupation'    => 'nullable|string|max:255',
            'spouse_income_tax_no' => 'nullable|string|max:50',
            'spouse_is_working'    => 'nullable|boolean',
            'spouse_is_disabled'   => 'nullable|boolean',
        ]);

        $employee = $this->getOrCreateEmployee();
        \App\Models\EmployeeSpouseDetail::create([
            'employee_id'   => $employee->id,
            'name'          => $validated['spouse_name'],
            'address'       => $validated['spouse_address'],
            'nric_no'       => $validated['spouse_nric_no'],
            'tel_no'        => $validated['spouse_tel_no'],
            'occupation'    => $validated['spouse_occupation'],
            'income_tax_no' => $validated['spouse_income_tax_no'],
            'is_working'    => $request->boolean('spouse_is_working'),
            'is_disabled'   => $request->boolean('spouse_is_disabled'),
        ]);

        return back()->with('success', 'Spouse information added.');
    }

    // ── Delete a spouse record ────────────────────────────────────────────
    public function deleteSpouse(Request $request, int $spouseId)
    {
        $employee = $this->getOrCreateEmployee();
        \App\Models\EmployeeSpouseDetail::where('employee_id', $employee->id)
            ->where('id', $spouseId)->delete();
        return back()->with('success', 'Spouse record removed.');
    }

    // ── Update emergency contacts ─────────────────────────────────────────
    public function updateEmergency(Request $request)
    {
        $request->validate([
            'emergency.1.name'         => 'required|string|max:255',
            'emergency.1.tel_no'       => 'required|string|max:30',
            'emergency.1.relationship' => 'required|string|max:100',
            'emergency.2.name'         => 'required|string|max:255',
            'emergency.2.tel_no'       => 'required|string|max:30',
            'emergency.2.relationship' => 'required|string|max:100',
        ]);

        $employee = $this->getOrCreateEmployee();
        foreach ([1, 2] as $order) {
            $ec = $request->input("emergency.{$order}");
            EmployeeEmergencyContact::updateOrCreate(
                ['employee_id' => $employee->id, 'contact_order' => $order],
                [
                    'name'         => $ec['name'],
                    'tel_no'       => $ec['tel_no'],
                    'relationship' => $ec['relationship'],
                ]
            );
        }

        return back()->with('success', 'Emergency contacts updated.');
    }

    // ── Update child registration ─────────────────────────────────────────
    public function updateChildren(Request $request)
    {
        $validated = $request->validate([
            'cat_a_100' => 'nullable|integer|min:0|max:99',
            'cat_a_50'  => 'nullable|integer|min:0|max:99',
            'cat_b_100' => 'nullable|integer|min:0|max:99',
            'cat_b_50'  => 'nullable|integer|min:0|max:99',
            'cat_c_100' => 'nullable|integer|min:0|max:99',
            'cat_c_50'  => 'nullable|integer|min:0|max:99',
            'cat_d_100' => 'nullable|integer|min:0|max:99',
            'cat_d_50'  => 'nullable|integer|min:0|max:99',
            'cat_e_100' => 'nullable|integer|min:0|max:99',
            'cat_e_50'  => 'nullable|integer|min:0|max:99',
        ]);

        $employee = $this->getOrCreateEmployee();
        EmployeeChildRegistration::updateOrCreate(
            ['employee_id' => $employee->id],
            array_map(fn($v) => (int)($v ?? 0), $validated)
        );

        return back()->with('success', 'Child registration updated.');
    }

    // ── Upload AARF ───────────────────────────────────────────────────────
    public function uploadAarf(Request $request)
    {
        $request->validate(['aarf_file' => 'required|file|mimes:pdf|max:5120']);
        $employee = $this->getOrCreateEmployee();
        $path     = $request->file('aarf_file')->store('aarfs', 'public');
        $employee->update(['aarf_file_path' => $path]);
        return back()->with('success', 'AARF uploaded successfully.');
    }

    // ── Download handbook / orientation ───────────────────────────────────
    public function download(string $type)
    {
        $files = [
            'handbook'    => public_path('documents/employee-handbook.pdf'),
            'orientation' => public_path('documents/orientation-slide.pdf'),
        ];
        if (!array_key_exists($type, $files)) abort(404);
        if (!file_exists($files[$type])) {
            return back()->with('info', 'This document is not yet available. Please contact HR.');
        }
        return response()->file($files[$type], [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . basename($files[$type]) . '"',
        ]);
    }

    private function getOrCreateEmployee(): Employee
    {
        $user = Auth::user();
        if ($user->employee) return $user->employee;
        return Employee::create(['user_id' => $user->id, 'active_from' => now()->toDateString()]);
    }

    // ── Authenticated consent acknowledgement ────────────────────────────
    public function submitConsent(Request $request)
    {
        $user     = Auth::user();
        $employee = $user->employee;

        // Also update PersonalDetail if linked via onboarding
        $personalDetail = $employee?->onboarding?->personalDetail;
        if ($personalDetail && !$personalDetail->consent_given_at) {
            $personalDetail->update([
                'consent_given_at' => now(),
                'consent_ip'       => $request->ip(),
            ]);
        }

        // Update Employee record directly
        $employee?->update([
            'consent_given_at' => now(),
            'consent_ip'       => $request->ip(),
        ]);

        // Also mark any pending EmployeeEditLog consent as acknowledged
        if ($employee) {
            $pendingLog = \App\Models\EmployeeEditLog::where('employee_id', $employee->id)
                ->where('consent_required', true)
                ->whereNull('acknowledged_at')
                ->whereNotNull('consent_token')
                ->where(function ($q) { $q->whereNull('consent_token_expires_at')->orWhere('consent_token_expires_at', '>', now()); })
                ->latest()
                ->first();
            if ($pendingLog) {
                $pendingLog->update([
                    'acknowledged_by_user_id' => $user->id,
                    'acknowledged_by_name'    => $user->name,
                    'acknowledged_at'         => now(),
                ]);
            }
        }

        return back()->with('success', 'Thank you. Your Declaration & Consent has been recorded.');
    }

    // ── Employee record re-consent (triggered by HR edit) ─────────────────
    public function showReConsent(Request $request)
    {
        $token   = $request->query('token');
        $editLog = \App\Models\EmployeeEditLog::where('consent_token', $token)->first();

        if (!$editLog) {
            abort(404, 'Consent request not found or invalid link.');
        }

        $employee = $editLog->employee;
        return view('profile.consent-acknowledge', compact('employee', 'editLog'));
    }

    public function storeReConsent(Request $request)
    {
        $request->validate([
            'token'       => 'required|string',
            'edit_log_id' => 'required|integer',
        ]);

        $editLog = \App\Models\EmployeeEditLog::where('id', $request->edit_log_id)
            ->where('consent_token', $request->token)
            ->first();

        if (!$editLog) {
            return back()->withErrors(['token' => 'Invalid or expired consent link.']);
        }
        if ($editLog->isAcknowledged()) {
            return redirect()->route('user.dashboard')->with('info', 'You have already acknowledged this consent.');
        }
        if ($editLog->isTokenExpired()) {
            return back()->withErrors(['token' => 'This consent link has expired. Please contact HR.']);
        }

        $user = Auth::user();
        $editLog->update([
            'acknowledged_by_user_id' => $user->id,
            'acknowledged_by_name'    => $user->name,
            'acknowledged_at'         => now(),
            'acknowledgement_notes'   => $request->input('acknowledgement_notes'),
        ]);

        // Update employee consent timestamp
        $editLog->employee?->update(['consent_given_at' => now()]);

        return redirect()->route('profile')
            ->with('success', 'Thank you! Your Declaration & Consent has been acknowledged.');
    }
}