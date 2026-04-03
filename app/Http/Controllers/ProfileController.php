<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use App\Models\Employee;
use App\Models\EmployeeEditLog;
use App\Models\EmployeeEducationHistory;
use App\Models\EmployeeSpouseDetail;
use App\Models\EmployeeEmergencyContact;
use App\Models\EmployeeChildRegistration;
use App\Models\Onboarding;
use App\Mail\EmployeeConsentRequestMail;

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

    // ── Unified update: Sections A, F, G, H, I in one request ────────────
    public function update(Request $request)
    {
        $employee = $this->getOrCreateEmployee();

        $request->validate([
            // Section A
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
            // Section F
            'edu_qualification.*'     => 'nullable|string|max:255',
            'edu_institution.*'       => 'nullable|string|max:255',
            'edu_year.*'              => 'nullable|integer|min:1950|max:2099',
            'edu_experience_total'    => 'nullable|string|max:10',
            'edu_certificate.*.*'     => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120',
            'edu_cert_keep.*.*'       => 'nullable|string',
            'edu_delete_ids'          => 'nullable|string',
            // Section G
            'del_spouse_ids'          => 'nullable|string',
            'spouses.*.id'            => 'nullable|integer',
            'spouses.*.name'          => 'nullable|string|max:255',
            'spouses.*.nric_no'       => 'nullable|string|max:50',
            'spouses.*.tel_no'        => 'nullable|string|max:30',
            'spouses.*.occupation'    => 'nullable|string|max:255',
            'spouses.*.income_tax_no' => 'nullable|string|max:50',
            'spouses.*.address'       => 'nullable|string',
            'spouses.*.is_working'    => 'nullable|boolean',
            'spouses.*.is_disabled'   => 'nullable|boolean',
            // Section H
            'emergency.1.name'         => 'required|string|max:255',
            'emergency.1.tel_no'       => 'required|string|max:30',
            'emergency.1.relationship' => 'required|string|max:100',
            'emergency.2.name'         => 'required|string|max:255',
            'emergency.2.tel_no'       => 'required|string|max:30',
            'emergency.2.relationship' => 'required|string|max:100',
            // Section I
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

        // Load relationships for snapshots
        $employee->load(['educationHistories', 'spouseDetails', 'emergencyContacts', 'childRegistration']);

        $oldSectionA = md5(serialize([
            $employee->full_name, $employee->official_document_id, $employee->date_of_birth,
            $employee->sex, $employee->marital_status, $employee->religion, $employee->race,
            $employee->personal_contact_number, $employee->personal_email, $employee->bank_account_number,
            $employee->bank_name, $employee->epf_no, $employee->income_tax_no, $employee->socso_no,
            $employee->residential_address, $employee->is_disabled, $employee->preferred_name,
            $employee->house_tel_no, $employee->nric_file_paths,
        ]));
        $oldEduHash    = md5(serialize($employee->educationHistories->map(fn($e) => [$e->id, $e->qualification, $e->institution, $e->year_graduated])->toArray()));
        $oldSpouseHash = md5(serialize($employee->spouseDetails->map(fn($s) => [$s->id, $s->name, $s->tel_no])->toArray()));
        $oldEcHash     = md5(serialize($employee->emergencyContacts->sortBy('contact_order')->map(fn($c) => [$c->name, $c->tel_no, $c->relationship, $c->contact_order])->values()->toArray()));
        $childFields   = ['cat_a_100','cat_a_50','cat_b_100','cat_b_50','cat_c_100','cat_c_50','cat_d_100','cat_d_50','cat_e_100','cat_e_50'];
        $oldChHash     = md5(serialize($employee->childRegistration?->only($childFields) ?? []));

        // ── Section A ────────────────────────────────────────────────────────
        $bankName = in_array($request->input('bank_name') ?? '', ['Other', 'other'])
            ? ($request->input('bank_name_other') ?? null)
            : ($request->input('bank_name') ?? null);

        $aFields = [
            'full_name'               => $request->input('full_name'),
            'preferred_name'          => $request->input('preferred_name'),
            'official_document_id'    => $request->input('official_document_id'),
            'date_of_birth'           => $request->input('date_of_birth'),
            'sex'                     => $request->input('sex'),
            'marital_status'          => $request->input('marital_status'),
            'religion'                => $request->input('religion'),
            'race'                    => $request->input('race'),
            'is_disabled'             => $request->boolean('is_disabled'),
            'personal_contact_number' => $request->input('personal_contact_number'),
            'house_tel_no'            => $request->input('house_tel_no'),
            'personal_email'          => $request->input('personal_email'),
            'bank_account_number'     => $request->input('bank_account_number'),
            'bank_name'               => $bankName,
            'epf_no'                  => $request->input('epf_no'),
            'income_tax_no'           => $request->input('income_tax_no'),
            'socso_no'                => $request->input('socso_no'),
            'residential_address'     => $request->input('residential_address'),
        ];

        $existingNric  = $employee->nric_file_paths ?? ($employee->nric_file_path ? [$employee->nric_file_path] : []);
        $keepSubmitted = $request->has('nric_keep_submitted');
        if ($keepSubmitted) {
            $keptNric     = (array) $request->input('nric_keep_paths', []);
            $existingNric = array_values(array_intersect($existingNric, $keptNric));
        }
        $newNricPaths = [];
        if ($request->hasFile('nric_files')) {
            foreach ($request->file('nric_files') as $file) {
                if ($file && $file->isValid()) {
                    $newNricPaths[] = $file->store('nric_documents', 'public');
                }
            }
        }
        $mergedNric = array_values(array_merge($existingNric, $newNricPaths));
        if (!empty($mergedNric)) {
            $aFields['nric_file_paths'] = $mergedNric;
            $aFields['nric_file_path']  = $mergedNric[0];
        } elseif ($keepSubmitted) {
            $aFields['nric_file_paths'] = null;
            $aFields['nric_file_path']  = null;
        }

        $employee->update($aFields);

        $user = Auth::user();
        if ($user->name !== $request->input('full_name')) {
            $user->update(['name' => $request->input('full_name')]);
        }

        $employee->refresh();
        if ($employee->onboarding_id) {
            $ob = \App\Models\Onboarding::with('personalDetail')->find($employee->onboarding_id);
            if ($ob?->personalDetail) {
                $ob->personalDetail->update([
                    'full_name'               => $employee->full_name,
                    'preferred_name'          => $employee->preferred_name,
                    'official_document_id'    => $employee->official_document_id,
                    'date_of_birth'           => $employee->date_of_birth,
                    'sex'                     => $employee->sex,
                    'marital_status'          => $employee->marital_status,
                    'religion'                => $employee->religion,
                    'race'                    => $employee->race,
                    'is_disabled'             => $employee->is_disabled,
                    'residential_address'     => $employee->residential_address,
                    'personal_contact_number' => $employee->personal_contact_number,
                    'house_tel_no'            => $employee->house_tel_no,
                    'personal_email'          => $employee->personal_email,
                    'bank_account_number'     => $employee->bank_account_number,
                    'bank_name'               => $employee->bank_name,
                    'epf_no'                  => $employee->epf_no,
                    'income_tax_no'           => $employee->income_tax_no,
                    'socso_no'                => $employee->socso_no,
                ]);
            }
        }

        $existingOffboarding = \App\Models\Offboarding::where(function ($q) use ($employee) {
            $q->where('employee_id', $employee->id);
            if ($employee->onboarding_id) {
                $q->orWhere('onboarding_id', $employee->onboarding_id);
            }
        })->first();
        if ($existingOffboarding) {
            $existingOffboarding->update([
                'full_name'      => $employee->full_name,
                'personal_email' => $employee->personal_email,
            ]);
        }

        // ── Section F: Education ──────────────────────────────────────────────
        $eduDeleteIds = array_filter(explode(',', $request->input('edu_delete_ids', '')));
        if (!empty($eduDeleteIds)) {
            EmployeeEducationHistory::where('employee_id', $employee->id)
                ->whereIn('id', $eduDeleteIds)->delete();
        }

        $expTotal = $request->input('edu_experience_total');
        foreach ($request->input('edu_qualification', []) as $i => $qual) {
            if (empty(trim((string) $qual))) continue;

            $existingId     = $request->input("edu_id.{$i}");
            $yearsExp       = ($i === 0) ? $expTotal : null;
            $keepPaths      = $request->input("edu_cert_keep.{$i}", []);
            $keepSubmitted  = $request->has("edu_cert_keep_sent.{$i}");

            if ($existingId && $keepSubmitted) {
                // User explicitly managed this row's certs — apply the keep list (may be empty = remove all)
                $row = EmployeeEducationHistory::where('employee_id', $employee->id)->find($existingId);
                $allExisting  = $row ? ($row->certificate_paths ?? ($row->certificate_path ? [$row->certificate_path] : [])) : [];
                $keptExisting = array_values(array_intersect($allExisting, (array) $keepPaths));
            } elseif ($existingId) {
                $row = EmployeeEducationHistory::where('employee_id', $employee->id)->find($existingId);
                $keptExisting = $row ? ($row->certificate_paths ?? ($row->certificate_path ? [$row->certificate_path] : [])) : [];
            } else {
                $keptExisting = [];
            }

            $newCertPaths = [];
            if ($request->hasFile("edu_certificate.{$i}")) {
                foreach ((array) $request->file("edu_certificate.{$i}") as $certFile) {
                    if ($certFile && $certFile->isValid()) {
                        $newCertPaths[] = $certFile->store('education_certificates', 'public');
                    }
                }
            }

            $mergedCerts = array_slice(array_values(array_merge($keptExisting, $newCertPaths)), 0, 5);

            if ($existingId) {
                if (!isset($row)) {
                    $row = EmployeeEducationHistory::where('employee_id', $employee->id)->find($existingId);
                }
                if ($row) {
                    $row->update([
                        'qualification'     => $qual,
                        'institution'       => $request->input("edu_institution.{$i}"),
                        'year_graduated'    => $request->input("edu_year.{$i}"),
                        'years_experience'  => $yearsExp,
                        'certificate_path'  => $mergedCerts[0] ?? null,
                        'certificate_paths' => !empty($mergedCerts) ? $mergedCerts : null,
                    ]);
                }
            } else {
                EmployeeEducationHistory::create([
                    'employee_id'       => $employee->id,
                    'qualification'     => $qual,
                    'institution'       => $request->input("edu_institution.{$i}"),
                    'year_graduated'    => $request->input("edu_year.{$i}"),
                    'years_experience'  => $yearsExp,
                    'certificate_path'  => $mergedCerts[0] ?? null,
                    'certificate_paths' => !empty($mergedCerts) ? $mergedCerts : null,
                ]);
            }
        }

        // ── Section G: Spouse ─────────────────────────────────────────────────
        $delSpouseIds = array_filter(explode(',', $request->input('del_spouse_ids', '')));
        if (!empty($delSpouseIds)) {
            EmployeeSpouseDetail::where('employee_id', $employee->id)
                ->whereIn('id', $delSpouseIds)->delete();
        }

        foreach ($request->input('spouses', []) as $spouseData) {
            if (empty(trim((string) ($spouseData['name'] ?? '')))) continue;
            $sid     = $spouseData['id'] ?? null;
            $payload = [
                'name'          => $spouseData['name'] ?? null,
                'nric_no'       => $spouseData['nric_no'] ?? null,
                'tel_no'        => $spouseData['tel_no'] ?? null,
                'occupation'    => $spouseData['occupation'] ?? null,
                'income_tax_no' => $spouseData['income_tax_no'] ?? null,
                'address'       => $spouseData['address'] ?? null,
                'is_working'    => !empty($spouseData['is_working']),
                'is_disabled'   => !empty($spouseData['is_disabled']),
            ];
            if ($sid) {
                EmployeeSpouseDetail::where('employee_id', $employee->id)->where('id', $sid)->update($payload);
            } else {
                EmployeeSpouseDetail::create(array_merge($payload, ['employee_id' => $employee->id]));
            }
        }

        // ── Section H: Emergency Contacts ────────────────────────────────────
        foreach ([1, 2] as $order) {
            $ec = $request->input("emergency.{$order}");
            if (empty($ec['name'] ?? '')) continue;
            EmployeeEmergencyContact::updateOrCreate(
                ['employee_id' => $employee->id, 'contact_order' => $order],
                [
                    'name'         => $ec['name'],
                    'tel_no'       => $ec['tel_no'],
                    'relationship' => $ec['relationship'],
                ]
            );
        }

        // ── Section I: Children ───────────────────────────────────────────────
        $childData = [];
        foreach ($childFields as $f) {
            $childData[$f] = (int) ($request->input($f) ?? 0);
        }
        EmployeeChildRegistration::updateOrCreate(['employee_id' => $employee->id], $childData);

        // ── Detect changes and send ONE consent email ─────────────────────────
        $employee->refresh();
        $employee->load(['educationHistories', 'spouseDetails', 'emergencyContacts', 'childRegistration']);

        $newSectionA = md5(serialize([
            $employee->full_name, $employee->official_document_id, $employee->date_of_birth,
            $employee->sex, $employee->marital_status, $employee->religion, $employee->race,
            $employee->personal_contact_number, $employee->personal_email, $employee->bank_account_number,
            $employee->bank_name, $employee->epf_no, $employee->income_tax_no, $employee->socso_no,
            $employee->residential_address, $employee->is_disabled, $employee->preferred_name,
            $employee->house_tel_no, $employee->nric_file_paths,
        ]));
        $newEduHash    = md5(serialize($employee->educationHistories->map(fn($e) => [$e->id, $e->qualification, $e->institution, $e->year_graduated])->toArray()));
        $newSpouseHash = md5(serialize($employee->spouseDetails->map(fn($s) => [$s->id, $s->name, $s->tel_no])->toArray()));
        $newEcHash     = md5(serialize($employee->emergencyContacts->sortBy('contact_order')->map(fn($c) => [$c->name, $c->tel_no, $c->relationship, $c->contact_order])->values()->toArray()));
        $newChHash     = md5(serialize($employee->childRegistration?->only($childFields) ?? []));

        $changedSections = [];
        if ($oldSectionA !== $newSectionA)       $changedSections[] = 'Section A — Personal Details';
        if ($oldEduHash !== $newEduHash)          $changedSections[] = 'Section F — Education & Work History';
        if ($oldSpouseHash !== $newSpouseHash)    $changedSections[] = 'Section G — Spouse Information';
        if ($oldEcHash !== $newEcHash)            $changedSections[] = 'Section H — Emergency Contacts';
        if ($oldChHash !== $newChHash)            $changedSections[] = 'Section I — Child Registration';

        if (!empty($changedSections)) {
            $this->triggerProfileEditConsent($employee, $changedSections);
            return back()->with('success', 'Profile updated. A consent re-acknowledgement email has been sent to you.');
        }

        return back()->with('success', 'Profile saved (no changes detected).');
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

        // Handle NRIC file keep/delete and new uploads
        // nric_keep_paths[] carries the paths the user chose to keep; anything not in that list is removed.
        // nric_keep_submitted sentinel is always present in the form, so we can distinguish
        // "user removed all files" (empty keep list) from "form did not include the field at all".
        $employee = $this->getOrCreateEmployee();
        $existingNric = $employee->nric_file_paths ?? ($employee->nric_file_path ? [$employee->nric_file_path] : []);
        $keepSubmitted = $request->has('nric_keep_submitted');
        if ($keepSubmitted) {
            $keptNric     = (array) $request->input('nric_keep_paths', []);
            $existingNric = array_values(array_intersect($existingNric, $keptNric));
        }
        $newNricPaths = [];
        if ($request->hasFile('nric_files')) {
            foreach ($request->file('nric_files') as $file) {
                if ($file && $file->isValid()) {
                    $newNricPaths[] = $file->store('nric_documents', 'public');
                }
            }
        }
        $mergedNric = array_values(array_merge($existingNric, $newNricPaths));
        if (!empty($mergedNric)) {
            $validated['nric_file_paths'] = $mergedNric;
            $validated['nric_file_path']  = $mergedNric[0];
        } elseif ($keepSubmitted) {
            $validated['nric_file_paths'] = null;
            $validated['nric_file_path']  = null;
        }
        unset($validated['nric_files']);

        $employee->update($validated);

        $user = Auth::user();
        if ($user->name !== $validated['full_name']) {
            $user->update(['name' => $validated['full_name']]);
        }

        // ── Sync to onboarding personal_details ──────────────────────────
        $employee->refresh();
        if ($employee->onboarding_id) {
            $ob = \App\Models\Onboarding::with('personalDetail')->find($employee->onboarding_id);
            if ($ob?->personalDetail) {
                $ob->personalDetail->update([
                    'full_name'               => $employee->full_name,
                    'preferred_name'          => $employee->preferred_name,
                    'official_document_id'    => $employee->official_document_id,
                    'date_of_birth'           => $employee->date_of_birth,
                    'sex'                     => $employee->sex,
                    'marital_status'          => $employee->marital_status,
                    'religion'                => $employee->religion,
                    'race'                    => $employee->race,
                    'is_disabled'             => $employee->is_disabled,
                    'residential_address'     => $employee->residential_address,
                    'personal_contact_number' => $employee->personal_contact_number,
                    'house_tel_no'            => $employee->house_tel_no,
                    'personal_email'          => $employee->personal_email,
                    'bank_account_number'     => $employee->bank_account_number,
                    'bank_name'               => $employee->bank_name,
                    'epf_no'                  => $employee->epf_no,
                    'income_tax_no'           => $employee->income_tax_no,
                    'socso_no'                => $employee->socso_no,
                ]);
            }
        }

        // ── Sync to existing offboarding record ──────────────────────────
        $existingOffboarding = \App\Models\Offboarding::where(function ($q) use ($employee) {
            $q->where('employee_id', $employee->id);
            if ($employee->onboarding_id) {
                $q->orWhere('onboarding_id', $employee->onboarding_id);
            }
        })->first();
        if ($existingOffboarding) {
            $existingOffboarding->update([
                'full_name'      => $employee->full_name,
                'personal_email' => $employee->personal_email,
            ]);
        }

        $this->triggerProfileEditConsent($employee, ['Section A — Personal Details']);

        return back()->with('success', 'Personal information updated. A consent re-acknowledgement email has been sent to you.');
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

        // ── Sync to onboarding work_details ──────────────────────────────
        $employee->refresh();
        if ($employee->onboarding_id) {
            $ob = \App\Models\Onboarding::with('workDetail')->find($employee->onboarding_id);
            if ($ob?->workDetail) {
                $ob->workDetail->update([
                    'designation'       => $employee->designation,
                    'department'        => $employee->department,
                    'company'           => $employee->company,
                    'office_location'   => $employee->office_location,
                    'reporting_manager' => $employee->reporting_manager,
                    'company_email'     => $employee->company_email,
                    'employment_type'   => $employee->employment_type,
                    'start_date'        => $employee->start_date,
                ]);
            }
        }

        // ── Sync to existing offboarding record ──────────────────────────
        $existingOffboarding = \App\Models\Offboarding::where(function ($q) use ($employee) {
            $q->where('employee_id', $employee->id);
            if ($employee->onboarding_id) {
                $q->orWhere('onboarding_id', $employee->onboarding_id);
            }
        })->first();
        if ($existingOffboarding) {
            $existingOffboarding->update([
                'company'     => $employee->company,
                'department'  => $employee->department,
                'designation' => $employee->designation,
                'company_email' => $employee->company_email,
            ]);
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
            'edu_certificate.*.*'  => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120',
            'edu_cert_keep.*.*'    => 'nullable|string',
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

            $existingId = $request->input("edu_id.{$i}");
            $yearsExp   = ($i === 0) ? $expTotal : null;

            // Determine which existing cert paths to keep
            $keepPaths = $request->input("edu_cert_keep.{$i}", null);
            if ($existingId && $keepPaths !== null) {
                $row = EmployeeEducationHistory::where('employee_id', $employee->id)->find($existingId);
                $allExisting  = $row ? ($row->certificate_paths ?? ($row->certificate_path ? [$row->certificate_path] : [])) : [];
                $keptExisting = array_values(array_intersect($allExisting, (array)$keepPaths));
            } elseif ($existingId) {
                $row = EmployeeEducationHistory::where('employee_id', $employee->id)->find($existingId);
                $keptExisting = $row ? ($row->certificate_paths ?? ($row->certificate_path ? [$row->certificate_path] : [])) : [];
            } else {
                $keptExisting = [];
            }

            // Upload new cert files for this entry (edu_certificate[i][])
            $newCertPaths = [];
            if ($request->hasFile("edu_certificate.{$i}")) {
                foreach ((array)$request->file("edu_certificate.{$i}") as $certFile) {
                    if ($certFile && $certFile->isValid()) {
                        $newCertPaths[] = $certFile->store('education_certificates', 'public');
                    }
                }
            }

            $mergedCerts = array_values(array_merge($keptExisting, $newCertPaths));
            $mergedCerts = array_slice($mergedCerts, 0, 5);

            if ($existingId) {
                if (!isset($row)) {
                    $row = EmployeeEducationHistory::where('employee_id', $employee->id)->find($existingId);
                }
                if ($row) {
                    $row->update([
                        'qualification'     => $qual,
                        'institution'       => $request->input("edu_institution.{$i}"),
                        'year_graduated'    => $request->input("edu_year.{$i}"),
                        'years_experience'  => $yearsExp,
                        'certificate_path'  => $mergedCerts[0] ?? null,
                        'certificate_paths' => !empty($mergedCerts) ? $mergedCerts : null,
                    ]);
                }
            } else {
                EmployeeEducationHistory::create([
                    'employee_id'       => $employee->id,
                    'qualification'     => $qual,
                    'institution'       => $request->input("edu_institution.{$i}"),
                    'year_graduated'    => $request->input("edu_year.{$i}"),
                    'years_experience'  => $yearsExp,
                    'certificate_path'  => $mergedCerts[0] ?? null,
                    'certificate_paths' => !empty($mergedCerts) ? $mergedCerts : null,
                ]);
            }
        }

        $this->triggerProfileEditConsent($employee, ['Section F — Education & Work History']);

        return back()->with('success', 'Education history updated. A consent re-acknowledgement email has been sent to you.');
    }

    // ── Update spouse details (add new) ──────────────────────────────────
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

        $this->triggerProfileEditConsent($employee, ['Section G — Spouse Information']);

        return back()->with('success', 'Spouse information added. A consent re-acknowledgement email has been sent to you.');
    }

    // ── Edit an existing spouse record ───────────────────────────────────
    public function editSpouse(Request $request, int $spouseId)
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
        \App\Models\EmployeeSpouseDetail::where('employee_id', $employee->id)
            ->where('id', $spouseId)
            ->update([
                'name'          => $validated['spouse_name'],
                'address'       => $validated['spouse_address'] ?? null,
                'nric_no'       => $validated['spouse_nric_no'] ?? null,
                'tel_no'        => $validated['spouse_tel_no'] ?? null,
                'occupation'    => $validated['spouse_occupation'] ?? null,
                'income_tax_no' => $validated['spouse_income_tax_no'] ?? null,
                'is_working'    => $request->boolean('spouse_is_working'),
                'is_disabled'   => $request->boolean('spouse_is_disabled'),
            ]);

        $this->triggerProfileEditConsent($employee, ['Section G — Spouse Information']);

        return back()->with('success', 'Spouse information updated. A consent re-acknowledgement email has been sent to you.');
    }

    // ── Delete a spouse record ────────────────────────────────────────────
    public function deleteSpouse(Request $request, int $spouseId)
    {
        $employee = $this->getOrCreateEmployee();
        \App\Models\EmployeeSpouseDetail::where('employee_id', $employee->id)
            ->where('id', $spouseId)->delete();
        $this->triggerProfileEditConsent($employee, ['Section G — Spouse Information']);
        return back()->with('success', 'Spouse record removed. A consent re-acknowledgement email has been sent to you.');
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

        $this->triggerProfileEditConsent($employee, ['Section H — Emergency Contacts']);

        return back()->with('success', 'Emergency contacts updated. A consent re-acknowledgement email has been sent to you.');
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

        $this->triggerProfileEditConsent($employee, ['Section I — Child Registration (LHDN Tax Relief)']);

        return back()->with('success', 'Child registration updated. A consent re-acknowledgement email has been sent to you.');
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

    // ── Helper: send consent request email for profile self-edits ────────
    private function triggerProfileEditConsent(Employee $employee, array $sectionsChanged): void
    {
        $token     = EmployeeEditLog::generateToken();
        $expiresAt = now()->addDays(7);

        $recipients = array_filter(array_unique([
            $employee->personal_email,
            $employee->company_email,
        ]));
        $recipientStr = implode(', ', $recipients);

        $log = EmployeeEditLog::create([
            'employee_id'              => $employee->id,
            'edited_by_user_id'        => Auth::id(),
            'edited_by_name'           => Auth::user()->name,
            'edited_by_role'           => Auth::user()->role ?? 'user',
            'sections_changed'         => $sectionsChanged,
            'change_notes'             => null,
            'consent_required'         => true,
            'consent_token'            => $token,
            'consent_token_expires_at' => $expiresAt,
            'consent_requested_at'     => now(),
            'consent_sent_to_email'    => $recipientStr ?: null,
        ]);

        if (!empty($recipients)) {
            foreach ($recipients as $email) {
                try {
                    Mail::to($email)->send(new EmployeeConsentRequestMail($employee, $log));
                } catch (\Exception $e) {
                    \Log::warning("Profile self-edit consent email failed for employee #{$employee->id} to {$email}: " . $e->getMessage());
                }
            }
        }
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