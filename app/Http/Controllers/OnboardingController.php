<?php

namespace App\Http\Controllers;

use App\Models\Onboarding;
use App\Models\PersonalDetail;
use App\Models\WorkDetail;
use App\Models\AssetProvisioning;
use App\Models\AssetInventory;
use App\Models\AssetAssignment;
use App\Models\Aarf;
use App\Models\Employee;
use App\Models\Offboarding;
use App\Models\User;
use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use App\Mail\WelcomeNewHire;
use App\Mail\ConsentRequestMail;
use App\Mail\CalendarInvite;
use App\Mail\OnboardingInviteMail;
use App\Mail\OnboardingConsentRequestMail;
use App\Mail\OnboardingEditNotificationMail;
use App\Models\OnboardingEditLog;
use Illuminate\Support\Str;

class OnboardingController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizeHrAccess();

        $query = Onboarding::with(['personalDetail', 'workDetail', 'assetProvisioning', 'aarf']);

        // Only hide past-start-date records when no search/date filter is active.
        // When the user searches by name or filters by date range, past records become visible.
        $hasFilter = $request->filled('search')
                  || $request->filled('start_date_from')
                  || $request->filled('start_date_to')
                  || $request->filled('company')
                  || $request->filled('position')
                  || $request->filled('department');

        if (!$hasFilter) {
            // Default: upcoming onboardings not yet activated, OR invite-submitted records awaiting HR completion
            $query->where(function ($q) {
                $q->where(function ($q2) {
                    // Normal: upcoming start date, not yet activated
                    $q2->whereHas('workDetail', fn($q3) => $q3->where('start_date', '>=', now()->toDateString()))
                       ->whereDoesntHave('employee');
                })->orWhere(function ($q2) {
                    // Invite-submitted: new hire filled in details, HR yet to complete
                    $q2->where('invite_submitted', true)
                       ->whereDoesntHave('employee');
                });
            });
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->whereHas('personalDetail', fn($q2) =>
                    $q2->where('full_name', 'like', "%{$search}%")
                       ->orWhere('personal_email', 'like', "%{$search}%")
                )->orWhereHas('workDetail', fn($q2) =>
                    $q2->where('designation', 'like', "%{$search}%")
                       ->orWhere('company', 'like', "%{$search}%")
                       ->orWhere('department', 'like', "%{$search}%")
                );
            });
        }

        if ($request->filled('start_date_from')) {
            $query->whereHas('workDetail', fn($q) => $q->where('start_date', '>=', $request->start_date_from));
        }
        if ($request->filled('start_date_to')) {
            $query->whereHas('workDetail', fn($q) => $q->where('start_date', '<=', $request->start_date_to));
        }

        if ($request->filled('company')) {
            $query->whereHas('workDetail', fn($q) => $q->where('company', $request->company));
        }
        if ($request->filled('position')) {
            $query->whereHas('workDetail', fn($q) => $q->where('designation', 'like', "%{$request->position}%"));
        }
        if ($request->filled('department')) {
            $query->whereHas('workDetail', fn($q) => $q->where('department', 'like', "%{$request->department}%"));
        }

        $onboardings = $query->latest()->paginate(15)->withQueryString();
        $companies   = Company::orderBy('name')->get(['name','address']);
        // Reporting manager candidates: active employees with manager-level roles
        $managers = Employee::whereNull('active_until')
            ->whereNotNull('full_name')
            ->whereNotNull('work_role')
            ->whereIn('work_role', [
                'hr_manager','it_manager','superadmin','manager',
            ])
            ->orderBy('full_name')
            ->get();

        // HR contacts: active employees with HR roles
        $hrUsers = Employee::whereNull('active_until')
            ->whereNotNull('full_name')
            ->whereNotNull('company_email')
            ->whereIn('work_role', ['hr_manager','hr_executive','hr_intern'])
            ->orderBy('full_name')
            ->get();

        // IT contacts: active employees with IT roles
        $itUsers = Employee::whereNull('active_until')
            ->whereNotNull('full_name')
            ->whereNotNull('company_email')
            ->whereIn('work_role', ['it_manager','it_executive','it_intern'])
            ->orderBy('full_name')
            ->get();

        $month = $request->input('month', now()->month);
        $year  = $request->input('year',  now()->year);
        $years = range(now()->year - 2, now()->year + 1);
        $months = [
            1=>'January',  2=>'February', 3=>'March',    4=>'April',
            5=>'May',      6=>'June',     7=>'July',     8=>'August',
            9=>'September',10=>'October', 11=>'November',12=>'December',
        ];

        // IT staff for Assigned PIC dropdown (superadmin/IT manager on HR onboarding page)
        $itStaff = User::whereIn('role', ['it_manager', 'it_executive', 'it_intern'])
            ->where('is_active', true)
            ->whereDoesntHave('employee', fn($q) => $q->where(function ($q2) {
                $q2->whereNotNull('active_until')
                   ->orWhere(fn($q3) => $q3->whereNotNull('exit_date')->where('exit_date', '<', now()->toDateString()));
            }))
            ->orderBy('name')
            ->with('employee')
            ->get();

        return view('hr.onboarding.page', compact('onboardings', 'companies', 'hrUsers', 'itUsers', 'managers', 'month', 'year', 'years', 'months', 'itStaff'));
    }

    public function create()
    {
        $this->authorizeCanAdd();
        return redirect()->route('onboarding.index');
    }

    public function store(Request $request)
    {
        $this->authorizeCanAdd();
        $validated = $this->validateOnboarding($request);

        // Spouse name + tel required when married
        if ($request->input('marital_status') === 'married') {
            $spouses = array_filter($request->input('spouses', []), fn($s) => !empty($s['name']));
            if (empty($spouses)) {
                return back()->withErrors(['spouses' => 'At least one spouse entry is required when Marital Status is Married.'])->withInput();
            }
            foreach (array_values($spouses) as $i => $sp) {
                if (empty($sp['tel_no'])) {
                    return back()->withErrors(["spouses.{$i}.tel_no" => 'Spouse Tel No. is required when Marital Status is Married.'])->withInput();
                }
            }
        }

        // Handle NRIC file uploads before transaction
        $nricPath = null; $nricPaths = [];
        if ($request->hasFile('nric_files')) {
            foreach ($request->file('nric_files') as $file) {
                if ($file && $file->isValid()) {
                    $nricPaths[] = $file->store('nric_documents', 'local');
                }
            }
            $nricPath = $nricPaths[0] ?? null;
        }

        $newOnboardingId = null;
        DB::transaction(function () use ($request, $validated, &$newOnboardingId, $nricPath, $nricPaths) {
            $hrEmails = $this->parseEmailArray($request->input('hr_emails', []));
            $itEmails = $this->parseEmailArray($request->input('it_emails', []));
            $googleId = $validated['company_email'] ?? null;

            $onboarding = Onboarding::create([
                'status'    => 'pending',
                'hr_email'  => $hrEmails[0] ?? null,
                'it_email'  => $itEmails[0] ?? null,
                'hr_emails' => $hrEmails,
                'it_emails' => $itEmails,
            ]);

            PersonalDetail::create([
                'onboarding_id'           => $onboarding->id,
                'full_name'               => $validated['full_name'],
                'preferred_name'          => $validated['preferred_name'] ?? null,
                'official_document_id'    => $validated['official_document_id'],
                'date_of_birth'           => $validated['date_of_birth'],
                'sex'                     => $validated['sex'],
                'marital_status'          => $validated['marital_status'],
                'religion'                => $validated['religion'],
                'race'                    => $validated['race'],
                'is_disabled'             => $request->boolean('is_disabled'),
                'residential_address'     => $validated['residential_address'],
                'personal_contact_number' => $validated['personal_contact_number'],
                'house_tel_no'            => $validated['house_tel_no'] ?? null,
                'personal_email'          => $validated['personal_email'],
                'bank_account_number'     => $validated['bank_account_number'],
                'bank_name'               => $this->resolveBankName($validated),
                'epf_no'                  => $validated['epf_no'] ?? null,
                'income_tax_no'           => $validated['income_tax_no'] ?? null,
                'socso_no'                => $validated['socso_no'] ?? null,
                'nric_file_path'          => $nricPath,
                'nric_file_paths'         => !empty($nricPaths) ? $nricPaths : null,
                'invite_staging_json'     => $this->buildStagingJson($request),
            ]);

            WorkDetail::create([
                'onboarding_id'           => $onboarding->id,
                'employee_status'         => $validated['employee_status'],
                'staff_status'            => $validated['staff_status'],
                'employment_type'         => $validated['employment_type'],
                'designation'             => $validated['designation'],
                'company'                 => $validated['company'],
                'office_location'         => $validated['office_location'],
                'reporting_manager'       => $validated['reporting_manager'],
                'reporting_manager_email' => $validated['reporting_manager_email'] ?? null,
                'start_date'              => $validated['start_date'],
                'exit_date'               => $validated['exit_date'] ?? null,
                'last_salary_date'        => Auth::user()->isHrManager() ? ($validated['last_salary_date'] ?? null) : null,
                'company_email'           => $validated['company_email'] ?? null,
                'google_id'               => $googleId,
                'department'              => $validated['department'] ?? null,
                'role'                    => $validated['role'] ?? 'others',
            ]);

            $assetProv = AssetProvisioning::create([
                'onboarding_id'       => $onboarding->id,
                'laptop_provision'    => $request->boolean('laptop_provision'),
                'monitor_set'         => $request->boolean('monitor_set'),
                'converter'           => $request->boolean('converter'),
                'company_phone'       => $request->boolean('company_phone'),
                'sim_card'            => $request->boolean('sim_card'),
                'access_card_request' => $request->boolean('access_card_request'),
                'office_keys'         => $validated['office_keys'] ?? null,
                'others'              => $validated['others'] ?? null,
            ]);

            $this->createAarf($onboarding);
            $this->autoAssignAssets($onboarding, $assetProv);
            $onboarding->update(['status' => 'active']);
            $newOnboardingId = $onboarding->id;
        });

        // On submission: send calendar invites to HR/IT only.
        // Welcome email to the new hire is sent on start_date by the ActivateEmployees command.
        $freshOnboarding = Onboarding::with([
            'personalDetail', 'workDetail', 'assetProvisioning', 'aarf'
        ])->find($newOnboardingId);

        if ($freshOnboarding) {
            $this->sendCalendarInvites($freshOnboarding);

            $consentEmail = $freshOnboarding->workDetail?->company_email;
            if ($consentEmail) {
                try {
                    Mail::to($consentEmail)->send(new ConsentRequestMail($freshOnboarding));
                } catch (\Exception $e) {
                    \Log::warning('Consent request email failed: ' . $e->getMessage());
                }
            }
        }

        return redirect()->route('onboarding.index')
            ->with('success', 'Onboarding record created! Calendar invites sent to HR and IT teams. A consent request email has been sent to the new hire\'s work email. Welcome email will be sent on their start date.');
    }

    public function show(Onboarding $onboarding)
    {
        $this->authorizeHrAccess();
        $onboarding->load(['personalDetail', 'workDetail', 'assetProvisioning', 'assetAssignments.asset', 'aarf', 'employee', 'offboarding', 'editLogs']);
        return view('hr.onboarding.show', compact('onboarding'));
    }

    public function edit(Onboarding $onboarding)
    {
        $this->authorizeCanEdit();
        $onboarding->load(['personalDetail', 'workDetail', 'assetProvisioning', 'editLogs', 'employee.user']);
        $hrUsers  = Employee::whereNull('active_until')->whereNotNull('full_name')->whereNotNull('company_email')
            ->whereIn('work_role', ['hr_manager','hr_executive','hr_intern'])->orderBy('full_name')->get();
        $itUsers  = Employee::whereNull('active_until')->whereNotNull('full_name')->whereNotNull('company_email')
            ->whereIn('work_role', ['it_manager','it_executive','it_intern'])->orderBy('full_name')->get();
        $managers = Employee::whereNull('active_until')->whereNotNull('full_name')->whereNotNull('work_role')
            ->whereIn('work_role', ['hr_manager','it_manager','superadmin','manager'])
            ->orderBy('full_name')->get();
        $companies  = Company::orderBy('name')->get(['name','address']);
        $canEditAll = Auth::user()->canEditAllOnboardingSections();
        return view('hr.onboarding.edit', compact('onboarding', 'hrUsers', 'itUsers', 'managers', 'companies', 'canEditAll'));
    }

    public function update(Request $request, Onboarding $onboarding)
    {
        $this->authorizeCanEdit();
        $user      = Auth::user();
        $validated = $this->validateOnboarding($request, isUpdate: true, user: $user);

        // Spouse name + tel required when married
        if ($request->input('marital_status') === 'married') {
            $spouses = array_filter($request->input('spouses', []), fn($s) => !empty($s['name']));
            if (empty($spouses)) {
                return back()->withErrors(['spouses' => 'At least one spouse entry is required when Marital Status is Married.'])->withInput();
            }
            foreach (array_values($spouses) as $i => $sp) {
                if (empty($sp['tel_no'])) {
                    return back()->withErrors(["spouses.{$i}.tel_no" => 'Spouse Tel No. is required when Marital Status is Married.'])->withInput();
                }
            }
        }

        // Capture old values for change detection (Sections A, F, G, H, I)
        $oldPersonal = $onboarding->personalDetail ? [
            'full_name'               => $onboarding->personalDetail->full_name,
            'official_document_id'    => $onboarding->personalDetail->official_document_id,
            'date_of_birth'           => $onboarding->personalDetail->date_of_birth?->toDateString(),
            'sex'                     => $onboarding->personalDetail->sex,
            'marital_status'          => $onboarding->personalDetail->marital_status,
            'religion'                => $onboarding->personalDetail->religion,
            'race'                    => $onboarding->personalDetail->race,
            'is_disabled'             => $onboarding->personalDetail->is_disabled,
            'residential_address'     => $onboarding->personalDetail->residential_address,
            'personal_contact_number' => $onboarding->personalDetail->personal_contact_number,
            'personal_email'          => $onboarding->personalDetail->personal_email,
            'bank_account_number'     => $onboarding->personalDetail->bank_account_number,
            'bank_name'               => $onboarding->personalDetail->bank_name,
            'epf_no'                  => $onboarding->personalDetail->epf_no,
            'income_tax_no'           => $onboarding->personalDetail->income_tax_no,
            'socso_no'                => $onboarding->personalDetail->socso_no,
        ] : null;
        $oldStaging = $onboarding->personalDetail?->invite_staging_json
            ? json_decode($onboarding->personalDetail->invite_staging_json, true)
            : [];

        // Handle NRIC file uploads before transaction
        $nricPath = null; $nricPaths = [];
        if ($request->hasFile('nric_files')) {
            foreach ($request->file('nric_files') as $file) {
                if ($file && $file->isValid()) {
                    $nricPaths[] = $file->store('nric_documents', 'local');
                }
            }
            $nricPath = $nricPaths[0] ?? null;
        }

        DB::transaction(function () use ($request, $validated, $onboarding, $user, $nricPath, $nricPaths) {
            $canEditAll = $user->canEditAllOnboardingSections();
            $googleId   = $validated['company_email'] ?? $onboarding->workDetail->google_id;
            $hrEmails   = $this->parseEmailArray($request->input('hr_emails', []));
            $itEmails   = $this->parseEmailArray($request->input('it_emails', []));

            $onboarding->update([
                'hr_email'  => $hrEmails[0] ?? $onboarding->hr_email,
                'it_email'  => $itEmails[0] ?? $onboarding->it_email,
                'hr_emails' => $hrEmails ?: $onboarding->hr_emails,
                'it_emails' => $itEmails ?: $onboarding->it_emails,
            ]);

            $onboarding->personalDetail->update([
                'full_name'               => $validated['full_name'],
                'preferred_name'          => $validated['preferred_name'] ?? null,
                'official_document_id'    => $validated['official_document_id'],
                'date_of_birth'           => $validated['date_of_birth'],
                'sex'                     => $validated['sex'],
                'marital_status'          => $validated['marital_status'],
                'religion'                => $validated['religion'],
                'race'                    => $validated['race'],
                'is_disabled'             => $request->boolean('is_disabled'),
                'residential_address'     => $validated['residential_address'],
                'personal_contact_number' => $validated['personal_contact_number'],
                'personal_email'          => $validated['personal_email'],
                'bank_account_number'     => $validated['bank_account_number'],
                'bank_name'               => $this->resolveBankName($validated),
                'epf_no'                  => $validated['epf_no'] ?? null,
                'income_tax_no'           => $validated['income_tax_no'] ?? null,
                'socso_no'                => $validated['socso_no'] ?? null,
                'nric_file_path'          => $nricPath ?: ($onboarding->personalDetail->nric_file_path ?? null),
                'nric_file_paths'         => !empty($nricPaths) ? array_merge($onboarding->personalDetail->nric_file_paths ?? [], $nricPaths) : ($onboarding->personalDetail->nric_file_paths ?? null),
                'invite_staging_json'     => $this->buildStagingJson($request, $onboarding->personalDetail->invite_staging_json),
            ]);

            $workData = [
                'employee_status'         => $validated['employee_status'],
                'staff_status'            => $validated['staff_status'],
                'employment_type'         => $validated['employment_type'],
                'designation'             => $validated['designation'],
                'company'                 => $validated['company'],
                'office_location'         => $validated['office_location'],
                'reporting_manager'       => $validated['reporting_manager'],
                'reporting_manager_email' => $validated['reporting_manager_email'] ?? null,
                'start_date'              => $validated['start_date'],
                'exit_date'               => $validated['exit_date'] ?? null,
                'company_email'           => $validated['company_email'] ?? null,
                'google_id'               => $googleId,
                'department'              => $validated['department'] ?? null,
            ];
            if ($user->isHrManager()) {
                $workData['last_salary_date'] = $validated['last_salary_date'] ?? null;
            }
            if ($canEditAll) {
                $workData['role'] = $validated['role'] ?? $onboarding->workDetail->role;
            }
            $onboarding->workDetail->update($workData);

            if ($canEditAll) {
                $onboarding->assetProvisioning->update([
                    'laptop_provision'    => $request->boolean('laptop_provision'),
                    'monitor_set'         => $request->boolean('monitor_set'),
                    'converter'           => $request->boolean('converter'),
                    'company_phone'       => $request->boolean('company_phone'),
                    'sim_card'            => $request->boolean('sim_card'),
                    'access_card_request' => $request->boolean('access_card_request'),
                    'office_keys'         => $validated['office_keys'] ?? null,
                    'others'              => $validated['others'] ?? null,
                ]);
            }

            if (isset($validated['employee_status']) && $validated['employee_status'] === 'resigned') {
                if (!$onboarding->offboarding) {
                    Offboarding::create([
                        'onboarding_id' => $onboarding->id,
                        'exit_date'     => $validated['exit_date'] ?? now(),
                        'reason'        => 'Resigned',
                        'remarks'       => $request->remarks ?? null,
                    ]);
                    $onboarding->update(['status' => 'offboarded']);
                }
            }
        });

        // ── Completion check: fire automations if invite_submitted record is now complete ──
        // Only runs if this record was created via invite link AND automations haven't fired yet
        $onboarding->refresh();
        if ($onboarding->invite_submitted && !$onboarding->calendar_invite_sent) {
            $w    = $onboarding->workDetail;
            $prov = $onboarding->assetProvisioning;

            $sectionBComplete = $w && $w->start_date && $w->designation && $w->company;
            $sectionCComplete = $prov && (
                $prov->laptop_provision || $prov->monitor_set || $prov->converter ||
                $prov->company_phone || $prov->sim_card || $prov->access_card_request ||
                $prov->office_keys || $prov->others
            );

            if ($sectionBComplete && $sectionCComplete) {
                // Create AARF if not yet created
                if (!$onboarding->aarf) {
                    $this->createAarf($onboarding);
                }
                $this->autoAssignAssets($onboarding, $prov);
                $onboarding->update(['status' => 'active']);
                $this->sendCalendarInvites($onboarding);
            }
        }

        // ── Detect which sections changed and create an edit log ──────────────
        $onboarding->refresh();
        $newPersonal = $onboarding->personalDetail ? [
            'full_name'               => $onboarding->personalDetail->full_name,
            'official_document_id'    => $onboarding->personalDetail->official_document_id,
            'date_of_birth'           => $onboarding->personalDetail->date_of_birth?->toDateString(),
            'sex'                     => $onboarding->personalDetail->sex,
            'marital_status'          => $onboarding->personalDetail->marital_status,
            'religion'                => $onboarding->personalDetail->religion,
            'race'                    => $onboarding->personalDetail->race,
            'is_disabled'             => $onboarding->personalDetail->is_disabled,
            'residential_address'     => $onboarding->personalDetail->residential_address,
            'personal_contact_number' => $onboarding->personalDetail->personal_contact_number,
            'personal_email'          => $onboarding->personalDetail->personal_email,
            'bank_account_number'     => $onboarding->personalDetail->bank_account_number,
            'bank_name'               => $onboarding->personalDetail->bank_name,
            'epf_no'                  => $onboarding->personalDetail->epf_no,
            'income_tax_no'           => $onboarding->personalDetail->income_tax_no,
            'socso_no'                => $onboarding->personalDetail->socso_no,
        ] : null;
        $newStaging = $onboarding->personalDetail?->invite_staging_json
            ? json_decode($onboarding->personalDetail->invite_staging_json, true)
            : [];

        $changedSections = [];
        if ($oldPersonal && $newPersonal && $oldPersonal !== $newPersonal) {
            $changedSections[] = 'Section A — Personal Details';
        }
        if (($oldStaging['education'] ?? null) !== ($newStaging['education'] ?? null)) {
            $changedSections[] = 'Section F — Education & Work History';
        }
        if (($oldStaging['spouses'] ?? null) !== ($newStaging['spouses'] ?? null)) {
            $changedSections[] = 'Section G — Spouse Information';
        }
        if (($oldStaging['emergency'] ?? null) !== ($newStaging['emergency'] ?? null)) {
            $changedSections[] = 'Section H — Emergency Contacts';
        }
        if (($oldStaging['children'] ?? null) !== ($newStaging['children'] ?? null)) {
            $changedSections[] = 'Section I — Child Registration';
        }

        $flashMessage = 'Record updated successfully.';

        if (!empty($changedSections)) {
            // Send to both personal email and work email (deduplicated)
            $recipients = array_values(array_filter(array_unique([
                $onboarding->personalDetail?->personal_email,
                $onboarding->workDetail?->company_email,
            ])));
            $recipientStr = implode(', ', $recipients);

            // Onboarding records use notification-only emails — no consent token or acknowledgement required.
            // (The full re-acknowledgement flow is reserved for active employee records.)
            $editLog = OnboardingEditLog::create([
                'onboarding_id'            => $onboarding->id,
                'edited_by_user_id'        => $user->id,
                'edited_by_name'           => $user->name,
                'edited_by_role'           => $user->role,
                'sections_changed'         => $changedSections,
                'change_notes'             => $request->input('remarks'),
                'consent_required'         => false,
                'consent_token'            => null,
                'consent_token_expires_at' => null,
                'consent_requested_at'     => null,
                'consent_sent_to_email'    => $recipientStr ?: null,
            ]);

            if (!empty($recipients)) {
                $sent = false;
                foreach ($recipients as $email) {
                    try {
                        Mail::to($email)->send(new OnboardingEditNotificationMail($onboarding, $editLog));
                        $sent = true;
                    } catch (\Exception $e) {
                        \Log::warning('Onboarding edit notification email failed to ' . $email . ': ' . $e->getMessage());
                    }
                }
                $flashMessage = $sent
                    ? 'Record updated. An email notification has been sent to ' . ($onboarding->personalDetail?->full_name ?? 'the new hire') . '.'
                    : 'Record updated. (Notification email could not be sent — please notify the new hire manually.)';
            } else {
                $flashMessage = 'Record updated. Changes to personal sections were logged.';
            }
        }

        return redirect()->route('onboarding.show', $onboarding)->with('success', $flashMessage);
    }

    // ── Upload / change onboarding profile photo (HR Manager / SuperAdmin) ──
    public function uploadAvatar(Request $request, Onboarding $onboarding)
    {
        $u = Auth::user();
        if (!$u->canEditOnboarding()) abort(403);

        $request->validate(['avatar' => 'required|image|mimes:jpeg,png,jpg,gif,webp|max:2048|valid_file_content']);

        $user = $onboarding->employee?->user;
        if (!$user) {
            return back()->with('error', 'No linked user account found for this onboarding record.');
        }

        if ($user->profile_picture) {
            \Illuminate\Support\Facades\Storage::disk('public')->delete($user->profile_picture);
        }

        $path = $request->file('avatar')->store('profile-pictures', 'public');
        $user->update(['profile_picture' => $path]);

        return back()->with('success', 'Profile photo updated successfully.');
    }

    public function showReConsent(Request $request, Onboarding $onboarding)
    {
        $token   = $request->query('token');
        $editLog = OnboardingEditLog::where('onboarding_id', $onboarding->id)
            ->where('consent_token', $token)
            ->first();

        if (!$editLog) {
            abort(404, 'Consent request not found or invalid link.');
        }

        $onboarding->load(['personalDetail', 'workDetail']);
        return view('hr.onboarding.consent-acknowledge', compact('onboarding', 'editLog'));
    }

    public function storeReConsent(Request $request, Onboarding $onboarding)
    {
        $request->validate([
            'token'       => 'required|string',
            'edit_log_id' => 'required|integer',
        ]);

        $editLog = OnboardingEditLog::where('id', $request->edit_log_id)
            ->where('onboarding_id', $onboarding->id)
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

        // Also update the personalDetail consent timestamp
        $onboarding->personalDetail?->update(['consent_given_at' => now()]);

        return redirect()->route('user.dashboard')
            ->with('success', 'Thank you! Your Declaration & Consent has been acknowledged.');
    }

    public function export(Request $request)
    {
        $u = Auth::user();
        if (!$u->isSuperadmin() && !$u->isHrManager() && !$u->isHrExecutive() && !$u->isItManager() && !$u->isItExecutive()) {
            abort(403);
        }

        $query = Onboarding::with(['personalDetail', 'workDetail', 'assetProvisioning']);
        if ($request->filled('search')) {
            $search = $request->search;
            $query->whereHas('personalDetail', fn($q) => $q->where('full_name', 'like', "%{$search}%"));
        }
        if ($request->filled('company')) {
            $query->whereHas('workDetail', fn($q) => $q->where('company', $request->company));
        }
        $onboardings = $query->latest()->get();
        $headers = [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => 'attachment; filename="onboarding_export_' . date('Y-m-d') . '.csv"',
        ];
        $callback = function () use ($onboardings) {
            $file = fopen('php://output', 'w');
            fputcsv($file, ['ID','Full Name','Preferred Name','Document ID','Date of Birth','Sex','Marital Status',
                'Religion','Race','Personal Email','Contact Number','Employee Status','Staff Status',
                'Employment Type','Designation','Department','Company','Office Location',
                'Reporting Manager','Reporting Manager Email','Start Date','Exit Date',
                'Company Email','Google ID','Laptop','Monitor','Converter','Phone','SIM','Access Card',
                'HR Emails','IT Emails','Calendar Sent','Welcome Sent','Created At']);
            foreach ($onboardings as $o) {
                $p = $o->personalDetail; $w = $o->workDetail; $a = $o->assetProvisioning;
                fputcsv($file, [$o->id,$p?->full_name,$p?->preferred_name,$p?->official_document_id,
                    $p?->date_of_birth,$p?->sex,$p?->marital_status,$p?->religion,$p?->race,
                    $p?->personal_email,$p?->personal_contact_number,$w?->employee_status,$w?->staff_status,
                    $w?->employment_type,$w?->designation,$w?->department,$w?->company,$w?->office_location,
                    $w?->reporting_manager,$w?->reporting_manager_email,$w?->start_date,$w?->exit_date,
                    $w?->company_email,$w?->google_id,
                    $a?->laptop_provision?'Yes':'No',$a?->monitor_set?'Yes':'No',
                    $a?->converter?'Yes':'No',$a?->company_phone?'Yes':'No',
                    $a?->sim_card?'Yes':'No',$a?->access_card_request?'Yes':'No',
                    implode('; ',$o->hr_emails??[]),implode('; ',$o->it_emails??[]),
                    $o->calendar_invite_sent?'Yes':'No',$o->welcome_email_sent?'Yes':'No',
                    $o->created_at->format('Y-m-d')]);
            }
            fclose($file);
        };
        return response()->stream($callback, 200, $headers);
    }

    public function getManagerEmail(Request $request)
    {
        $name = $request->input('name', '');
        $user = User::where('name', $name)->first();
        return response()->json(['email' => $user?->work_email ?? '']);
    }

    // ── Private helpers ───────────────────────────────────────────────────

    /**
     * Called on form submission.
     * Sends calendar invites to HR and IT teams only.
     * Does NOT send the welcome email — that is handled by ActivateEmployees on start_date.
     */
    private function sendCalendarInvites(Onboarding $onboarding): void
    {
        $w = $onboarding->workDetail;
        $calendarSent = false;

        // ── HR recipients ─────────────────────────────────────────────────
        // Start from selected HR emails (or fall back to all HR staff)
        $hrRecipients = $this->resolveRecipients(
            selected: $onboarding->hr_emails ?? [],
            fallbackRole: ['hr_manager','hr_executive','hr_intern']
        );
        // Always include the reporting manager if their email is set
        if ($w?->reporting_manager_email && filter_var($w->reporting_manager_email, FILTER_VALIDATE_EMAIL)) {
            $hrRecipients[] = $w->reporting_manager_email;
        }
        // Always guarantee every HR Manager is included regardless of selection
        $hrManagerEmails = User::where('role', 'hr_manager')
            ->whereNotNull('work_email')
            ->pluck('work_email')
            ->filter(fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL))
            ->toArray();
        $hrRecipients = $this->cleanEmails(array_merge($hrRecipients, $hrManagerEmails));

        // ── IT recipients ─────────────────────────────────────────────────
        // Start from selected IT emails (or fall back to all IT staff)
        $itRecipients = $this->resolveRecipients(
            selected: $onboarding->it_emails ?? [],
            fallbackRole: ['it_manager','it_executive','it_intern']
        );
        // Always guarantee every IT Manager is included regardless of selection
        $itManagerEmails = User::where('role', 'it_manager')
            ->whereNotNull('work_email')
            ->pluck('work_email')
            ->filter(fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL))
            ->toArray();
        $itRecipients = $this->cleanEmails(array_merge($itRecipients, $itManagerEmails));

        try {
            if (!empty($hrRecipients)) {
                Mail::to($hrRecipients[0])
                    ->cc(array_slice($hrRecipients, 1))
                    ->send(new CalendarInvite($onboarding, 'HR Team'));
            }
            if (!empty($itRecipients)) {
                Mail::to($itRecipients[0])
                    ->cc(array_slice($itRecipients, 1))
                    ->send(new CalendarInvite($onboarding, 'IT Team'));
            }
            $calendarSent = (!empty($hrRecipients) || !empty($itRecipients));
        } catch (\Exception $e) {
            \Log::error('Calendar invite failed for onboarding #' . $onboarding->id . ': ' . $e->getMessage());
        }

        $onboarding->update([
            'calendar_invite_sent' => $calendarSent,
            'welcome_email_sent'   => false, // stays false until start_date
        ]);
    }

    /**
     * Called by ActivateEmployees command on the new hire's start_date.
     * Public so the artisan command can call it directly.
     */
    public function sendWelcomeEmail(Onboarding $onboarding): bool
    {
        $p = $onboarding->personalDetail;
        $w = $onboarding->workDetail;

        $newHireEmail = $w?->company_email ?? $p?->personal_email;

        if (!$newHireEmail || !filter_var($newHireEmail, FILTER_VALIDATE_EMAIL)) {
            \Log::warning('Welcome email skipped — no valid email for onboarding #' . $onboarding->id);
            return false;
        }

        try {
            Mail::to($newHireEmail)->send(new WelcomeNewHire($onboarding));
            $onboarding->update(['welcome_email_sent' => true]);
            return true;
        } catch (\Exception $e) {
            \Log::error('Welcome email failed for onboarding #' . $onboarding->id . ': ' . $e->getMessage());
            return false;
        }
    }

    private function resolveRecipients(array $selected, array $fallbackRole): array
    {
        $selected = $this->cleanEmails($selected);
        if (!empty($selected)) return $selected;
        return User::whereIn('role', $fallbackRole)
            ->pluck('work_email')
            ->filter(fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL))
            ->values()->toArray();
    }

    private function cleanEmails(array $emails): array
    {
        return array_values(array_unique(array_filter(
            $emails,
            fn($e) => $e && filter_var(trim($e), FILTER_VALIDATE_EMAIL)
        )));
    }

    private function parseEmailArray(mixed $value): array
    {
        if (is_array($value)) return $this->cleanEmails($value);
        if (is_string($value) && $value !== '') return $this->cleanEmails([$value]);
        return [];
    }

    private function autoAssignAssets(Onboarding $onboarding, AssetProvisioning $prov): void
    {
        $typeMap = [
            'laptop_provision'=>'laptop','monitor_set'=>'monitor','converter'=>'converter',
            'company_phone'=>'phone','sim_card'=>'sim_card','access_card_request'=>'access_card',
        ];
        $employeeName = $onboarding->personalDetail?->full_name ?? "Onboarding #{$onboarding->id}";
        $timestamp    = now()->format('d M Y, h:i A');

        // Reload AARF fresh — createAarf() is called before this method now
        $aarf = \App\Models\Aarf::where('onboarding_id', $onboarding->id)->first();

        foreach ($typeMap as $provField => $assetType) {
            if ($prov->$provField) {
                $asset = AssetInventory::getAvailableByType($assetType);
                if ($asset) {
                    $employee = \App\Models\Employee::where('onboarding_id', $onboarding->id)->first();

                    AssetAssignment::create([
                        'onboarding_id'      => $onboarding->id,
                        'asset_inventory_id' => $asset->id,
                        'assigned_date'      => now(),
                        'status'             => 'assigned',
                    ]);

                    // Status = 'assigned', not 'unavailable', when assigned to someone
                    $asset->update([
                        'status'               => 'assigned',
                        'assigned_employee_id' => $employee?->id,
                        'asset_assigned_date'  => now()->toDateString(),
                    ]);

                    $asset->appendRemark("[{$timestamp}] Auto-assigned to {$employeeName} via onboarding.");

                    // Log to AARF so it appears in the remarks section of the AARF
                    if ($aarf) {
                        $aarf->appendAssetChange("Asset [{$asset->asset_tag}] ({$asset->brand} {$asset->model}) auto-assigned to {$employeeName} via onboarding.");
                        $aarf->addPendingAsset($asset->id);
                    }
                }
            }
        }
    }

    private function createAarf(Onboarding $onboarding): void
    {
        Aarf::create([
            'onboarding_id'         => $onboarding->id,
            'aarf_reference'        => Onboarding::generateAarfReference(),
            'acknowledgement_token' => Str::random(64),
        ]);
    }

    private function validateOnboarding(Request $request, bool $isUpdate = false, $user = null): array
    {
        $rules = [
            'full_name'=>'required|string|max:255','preferred_name'=>'nullable|string|max:100',
            'official_document_id'=>'required|string|max:50','date_of_birth'=>'required|date',
            'sex'=>'required|in:male,female','marital_status'=>'required|in:single,married,divorced,widowed',
            'religion'=>'required|string|max:100','race'=>'required|string|max:100',
            'is_disabled'=>'nullable|boolean',
            'residential_address'=>'required|string','personal_contact_number'=>'required|string|max:20',
            'house_tel_no'=>'nullable|string|max:20',
            'personal_email'=>'required|email','bank_account_number'=>'required|string|max:50',
            'bank_name'=>'nullable|string|max:100','bank_name_other'=>'nullable|string|max:100',
            'epf_no'=>'nullable|string|max:50','income_tax_no'=>'nullable|string|max:50',
            'socso_no'=>'nullable|string|max:50',
            'nric_files'=>'nullable|array|max:5','nric_files.*'=>'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120|valid_file_content',
            'edu_certificate'=>'nullable|array','edu_certificate.*'=>'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120|valid_file_content',
            'edu_existing_cert_paths'=>'nullable|array','edu_existing_cert_paths.*'=>'nullable|string',
            'employee_status'=>'required|in:active,resigned','staff_status'=>'required|in:existing,new,rehire',
            'employment_type'=>'required|in:permanent,intern,contract','designation'=>'required|string|max:255',
            'company'=>'required|string|max:255','office_location'=>'required|string|max:255',
            'reporting_manager'=>'required|string|max:255','reporting_manager_email'=>'nullable|email',
            'start_date'=>'required|date','exit_date'=>'nullable|date|after_or_equal:start_date',
            'last_salary_date'=>'nullable|date',
            'company_email'=>'nullable|email','google_id'=>'nullable|string|max:255',
            'department'=>'nullable|string|max:255',
            'hr_emails'=>'nullable|array','hr_emails.*'=>'email',
            'it_emails'=>'nullable|array','it_emails.*'=>'email',
        ];
        if (!$isUpdate || ($user && $user->canEditAllOnboardingSections())) {
            $rules['role']        = 'nullable|in:manager,senior_executive,executive_associate,director_hod,hr_manager,hr_executive,hr_intern,it_manager,it_executive,it_intern,superadmin,system_admin,others';
            $rules['office_keys'] = 'nullable|string';
            $rules['others']      = 'nullable|string';
        }
        return $request->validate($rules);
    }

    private function authorizeHrAccess(): void
    {
        $u = Auth::user();
        if (!$u->isHr() && !$u->isIt() && !$u->isSuperadmin() && !$u->isSystemAdmin()) abort(403);
    }

    private function authorizeCanAdd(): void
    {
        if (!Auth::user()->canAddOnboarding()) abort(403);
    }

    private function authorizeCanEdit(): void
    {
        if (!Auth::user()->canEditOnboarding()) abort(403);
    }

    private function resolveBankName(array $validated): ?string
    {
        $name = $validated['bank_name'] ?? null;
        if (in_array($name, ['Other', 'other', null])) {
            return $validated['bank_name_other'] ?? null;
        }
        return $name;
    }

    private function buildStagingJson(Request $request, ?string $existingJson = null): ?string
    {
        $hasEdu     = !empty(array_filter($request->input('edu_qualification', [])));
        $hasSpouse  = !empty($request->input('spouses', []));
        $hasEc      = !empty($request->input('emergency', []));
        $hasChild   = $request->has('cat_a_100');
        // If no staging-related fields are present in this POST, preserve the existing JSON unchanged
        if (!$hasEdu && !$hasSpouse && !$hasEc && !$hasChild) return $existingJson;

        $eduStaging = [];
        foreach ($request->input('edu_qualification', []) as $i => $qual) {
            if (empty(trim((string)$qual))) continue;

            // Per-entry existing cert paths (from hidden inputs edu_cert_existing[i][])
            $existingCerts = $request->input("edu_cert_existing.{$i}", []);

            // Per-entry new cert uploads (inline edit or new-entry panel, edu_cert_new[i][])
            $newCerts = [];
            if ($request->hasFile("edu_cert_new.{$i}")) {
                foreach ($request->file("edu_cert_new.{$i}") as $certFile) {
                    if ($certFile && $certFile->isValid()) {
                        $newCerts[] = $certFile->store('education_certificates', 'local');
                    }
                }
            }

            $certPaths = array_values(array_filter(array_merge($existingCerts, $newCerts)));

            $eduStaging[] = [
                'qualification'    => $qual,
                'institution'      => $request->input("edu_institution.{$i}"),
                'year_graduated'   => $request->input("edu_year.{$i}"),
                'years_experience' => null,
                'certificate_path'  => $certPaths[0] ?? null,
                'certificate_paths' => $certPaths,
            ];
        }

        $spousesStaging = [];
        foreach ($request->input('spouses', []) as $sp) {
            if (empty($sp['name'])) continue;
            // Normalise booleans so re-saves don't produce false diffs vs the original invite submission
            $sp['is_working']  = (bool)($sp['is_working']  ?? false);
            $sp['is_disabled'] = (bool)($sp['is_disabled'] ?? false);
            $spousesStaging[] = $sp;
        }

        return json_encode([
            'education'            => $eduStaging,
            'edu_experience_total' => $request->input('edu_experience_total'),
            'spouses'              => $spousesStaging,
            'emergency'            => $request->input('emergency', []),
            'children'             => [
                'cat_a_100' => (int)$request->input('cat_a_100', 0),
                'cat_a_50'  => (int)$request->input('cat_a_50',  0),
                'cat_b_100' => (int)$request->input('cat_b_100', 0),
                'cat_b_50'  => (int)$request->input('cat_b_50',  0),
                'cat_c_100' => (int)$request->input('cat_c_100', 0),
                'cat_c_50'  => (int)$request->input('cat_c_50',  0),
                'cat_d_100' => (int)$request->input('cat_d_100', 0),
                'cat_d_50'  => (int)$request->input('cat_d_50',  0),
                'cat_e_100' => (int)$request->input('cat_e_100', 0),
                'cat_e_50'  => (int)$request->input('cat_e_50',  0),
            ],
        ]);
    }
}