<?php

namespace App\Http\Controllers;

use App\Mail\OnboardingInviteMail;
use App\Models\Employee;
use App\Models\EmployeeEducationHistory;
use App\Models\EmployeeEmergencyContact;
use App\Models\EmployeeSpouseDetail;
use App\Models\EmployeeChildRegistration;
use App\Models\Onboarding;
use App\Models\PersonalDetail;
use App\Models\WorkDetail;
use App\Models\AssetProvisioning;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class OnboardingInviteController extends Controller
{
    // ── HR: Send invite link (POST) ───────────────────────────────────────
    public function send(Request $request)
    {
        $user = Auth::user();
        if (!$user->canAddOnboarding()) abort(403);

        $request->validate([
            'invite_email'   => 'required|email|max:255',
            'invite_company' => 'required|string|max:255',
        ]);

        $email       = strtolower(trim($request->invite_email));
        $companyName = trim($request->invite_company);
        $token       = Str::random(64);
        $expiresAt   = now()->addHours(24);
        $senderName  = $user->name ?? $user->work_email ?? 'HR Team';
        $inviteUrl   = null;

        DB::transaction(function () use ($email, $token, $expiresAt, $senderName, $companyName, &$inviteUrl) {
            $onboarding = Onboarding::create([
                'status'            => 'pending',
                'invite_token'      => $token,
                'invite_email'      => $email,
                'invite_expires_at' => $expiresAt,
                'invite_submitted'  => false,
                'hr_emails'         => [],
                'it_emails'         => [],
            ]);

            PersonalDetail::create([
                'onboarding_id'           => $onboarding->id,
                'full_name'               => null,
                'official_document_id'    => null,
                'date_of_birth'           => null,
                'sex'                     => null,
                'marital_status'          => null,
                'religion'                => null,
                'race'                    => null,
                'residential_address'     => null,
                'personal_contact_number' => null,
                'personal_email'          => null,
                'bank_account_number'     => null,
            ]);

            WorkDetail::create([
                'onboarding_id'   => $onboarding->id,
                'designation'     => null,
                'employment_type' => null,
                'start_date'      => null,
            ]);

            AssetProvisioning::create(['onboarding_id' => $onboarding->id]);

            $inviteUrl = route('onboarding.invite.form', $token);
            Mail::to($email)->send(new OnboardingInviteMail($inviteUrl, $email, $senderName, $companyName));
        });

        return back()->with('success', "Onboarding invite link sent to {$email}. The link expires in 24 hours.");
    }

    // ── Public: Show invite form (GET — no auth) ──────────────────────────
    public function showForm(string $token)
    {
        $onboarding = Onboarding::where('invite_token', $token)->first();

        if (!$onboarding) {
            return view('onboarding.invite-form', ['token' => $token, 'verified' => false, 'step' => 'verify'])
                ->with('error', 'This invite link is invalid.');
        }

        if ($onboarding->invite_submitted) {
            return redirect()->route('onboarding.invite.success')
                ->with('info', 'You have already submitted your details.');
        }

        if ($onboarding->invite_expires_at && now()->gt($onboarding->invite_expires_at)) {
            return view('onboarding.invite-form', ['token' => $token, 'verified' => false, 'step' => 'verify'])
                ->with('error', 'This invite link has expired. Please contact HR for a new link.');
        }

        return view('onboarding.invite-form', [
            'token'    => $token,
            'verified' => false,
            'step'     => 'verify',
        ]);
    }

    // ── Public: Verify email (POST — no auth) ─────────────────────────────
    public function verifyEmail(Request $request, string $token)
    {
        $request->validate(['email' => 'required|email']);

        $onboarding = Onboarding::where('invite_token', $token)->first();

        if (!$onboarding || $onboarding->invite_submitted) {
            return back()->with('error', 'This link is invalid or has already been used.');
        }

        if ($onboarding->invite_expires_at && now()->gt($onboarding->invite_expires_at)) {
            return back()->with('error', 'This invite link has expired. Please contact HR for a new link.');
        }

        if (strtolower(trim($request->email)) !== strtolower(trim($onboarding->invite_email))) {
            return back()->withErrors(['email' => 'The email address does not match our records. Please use the email address this invitation was sent to.']);
        }

        // Email verified — skip consent step, go straight to the form
        // Consent is captured at the end of the form via the "I Acknowledge" button
        return view('onboarding.invite-form', [
            'token'    => $token,
            'verified' => true,
            'step'     => 'form',
        ]);
    }

    // ── Public: Accept consent via AJAX (POST — no auth) ─────────────────
    public function acceptConsent(Request $request, string $token)
    {
        $onboarding = Onboarding::where('invite_token', $token)->first();

        if (!$onboarding || $onboarding->invite_submitted) {
            return response()->json(['success' => false, 'message' => 'Invalid or already submitted.'], 422);
        }

        if ($onboarding->invite_expires_at && now()->gt($onboarding->invite_expires_at)) {
            return response()->json(['success' => false, 'message' => 'Link expired.'], 422);
        }

        // Record consent timestamp + IP on the PersonalDetail placeholder
        $onboarding->personalDetail?->update([
            'consent_given_at' => now(),
            'consent_ip'       => $request->ip(),
        ]);

        return response()->json([
            'success'    => true,
            'timestamp'  => now()->format('d M Y, h:i A'),
        ]);
    }

    // ── Public: Submit personal details (POST — no auth) ──────────────────
    public function submit(Request $request, string $token)
    {
        $onboarding = Onboarding::where('invite_token', $token)->first();

        if (!$onboarding || $onboarding->invite_submitted) {
            return redirect()->route('onboarding.invite.success');
        }

        if ($onboarding->invite_expires_at && now()->gt($onboarding->invite_expires_at)) {
            return back()->with('error', 'This invite link has expired. Please contact HR for a new link.');
        }

        $validated = $request->validate([
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
            'residential_address'     => 'required|string',
            'personal_contact_number' => 'required|string|max:20',
            'house_tel_no'            => 'nullable|string|max:20',
            'personal_email'          => 'required|email',
            'bank_account_number'     => 'required|string|max:50',
            'bank_name'               => 'nullable|string|max:100',
            'bank_name_other'         => 'nullable|string|max:100',
            'epf_no'                  => 'nullable|string|max:50',
            'income_tax_no'           => 'nullable|string|max:50',
            'socso_no'                => 'nullable|string|max:50',
            'nric_files'              => 'nullable|array|max:5',
            'nric_files.*'            => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120',
            // Education
            'edu_qualification.*'        => 'nullable|string|max:255',
            'edu_institution.*'          => 'nullable|string|max:255',
            'edu_year.*'                 => 'nullable|integer|min:1950|max:2099',
            'edu_experience_total'       => 'nullable|string|max:10',
            'edu_certificate'            => 'nullable|array',
            'edu_certificate.*'          => 'nullable|array',
            'edu_certificate.*.*'        => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120',
            // Multiple spouses
            'spouses'                    => 'nullable|array',
            'spouses.*.name'             => 'required|string|max:255',
            'spouses.*.nric_no'          => 'nullable|string|max:50',
            'spouses.*.tel_no'           => 'nullable|string|max:30',
            'spouses.*.occupation'       => 'nullable|string|max:255',
            'spouses.*.income_tax_no'    => 'nullable|string|max:50',
            'spouses.*.address'          => 'nullable|string',
            'spouses.*.is_working'       => 'nullable|boolean',
            'spouses.*.is_disabled'      => 'nullable|boolean',
            // Emergency contacts (2 required)
            'emergency.1.name'         => 'required|string|max:255',
            'emergency.1.tel_no'       => 'required|string|max:30',
            'emergency.1.relationship' => 'required|string|max:100',
            'emergency.2.name'         => 'required|string|max:255',
            'emergency.2.tel_no'       => 'required|string|max:30',
            'emergency.2.relationship' => 'required|string|max:100',
            // Child registration
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

        // Spouse name + tel required when married
        if ($request->input('marital_status') === 'married') {
            $spouses = array_filter($request->input('spouses', []), fn($s) => !empty($s['name']));
            if (empty($spouses)) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'spouses' => ['At least one spouse entry is required when Marital Status is Married.'],
                ]);
            }
            foreach (array_values($spouses) as $i => $sp) {
                if (empty($sp['tel_no'])) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        "spouses.{$i}.tel_no" => ['Spouse Tel No. is required when Marital Status is Married.'],
                    ]);
                }
            }
        }

        // Resolve bank name (Other fallback)
        $bankName = (in_array($validated['bank_name'] ?? '', ['Other', 'other']))
            ? ($validated['bank_name_other'] ?? null)
            : ($validated['bank_name'] ?? null);

        // Handle multiple NRIC file uploads
        $nricPaths = [];
        if ($request->hasFile('nric_files')) {
            foreach ($request->file('nric_files') as $file) {
                if ($file && $file->isValid()) {
                    $nricPaths[] = $file->store('nric_documents', 'public');
                }
            }
        }
        $nricPath  = $nricPaths[0] ?? null; // legacy single column
        $nricPathsJson = !empty($nricPaths) ? $nricPaths : null;

        // Build education staging entries (cert files grouped by qualification index: edu_certificate[i][])
        $eduStaging = [];
        $eduCertGroups = $request->file('edu_certificate', []);
        foreach ($request->input('edu_qualification', []) as $i => $qual) {
            if (empty(trim($qual))) continue;
            $certPaths = [];
            $certFiles = $eduCertGroups[$i] ?? [];
            if (!is_array($certFiles)) $certFiles = [$certFiles];
            foreach ($certFiles as $certFile) {
                if ($certFile && $certFile->isValid()) {
                    $certPaths[] = $certFile->store('education_certificates', 'public');
                }
            }
            $eduStaging[] = [
                'qualification'    => $qual,
                'institution'      => $request->input("edu_institution.{$i}"),
                'year_graduated'   => $request->input("edu_year.{$i}"),
                'years_experience' => null,
                'certificate_path'  => $certPaths[0] ?? null,   // legacy single
                'certificate_paths' => $certPaths,              // all files
            ];
        }

        // Build spouses staging (multiple)
        $spousesStaging = [];
        foreach ($request->input('spouses', []) as $sp) {
            if (empty($sp['name'])) continue;
            $spousesStaging[] = [
                'name'          => $sp['name'],
                'nric_no'       => $sp['nric_no'] ?? null,
                'tel_no'        => $sp['tel_no'] ?? null,
                'occupation'    => $sp['occupation'] ?? null,
                'income_tax_no' => $sp['income_tax_no'] ?? null,
                'address'       => $sp['address'] ?? null,
                'is_working'    => (bool)($sp['is_working'] ?? false),
                'is_disabled'   => (bool)($sp['is_disabled'] ?? false),
            ];
        }

        // Build full staging payload
        $stagingJson = json_encode([
            'education'          => $eduStaging,
            'edu_experience_total' => $request->input('edu_experience_total'),
            'spouses'            => $spousesStaging,
            'emergency'          => $request->input('emergency', []),
            'children' => [
                'cat_a_100' => (int)($validated['cat_a_100'] ?? 0),
                'cat_a_50'  => (int)($validated['cat_a_50']  ?? 0),
                'cat_b_100' => (int)($validated['cat_b_100'] ?? 0),
                'cat_b_50'  => (int)($validated['cat_b_50']  ?? 0),
                'cat_c_100' => (int)($validated['cat_c_100'] ?? 0),
                'cat_c_50'  => (int)($validated['cat_c_50']  ?? 0),
                'cat_d_100' => (int)($validated['cat_d_100'] ?? 0),
                'cat_d_50'  => (int)($validated['cat_d_50']  ?? 0),
                'cat_e_100' => (int)($validated['cat_e_100'] ?? 0),
                'cat_e_50'  => (int)($validated['cat_e_50']  ?? 0),
            ],
        ]);

        DB::transaction(function () use ($request, $validated, $onboarding, $bankName, $nricPath, $nricPathsJson, $stagingJson) {
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
                'house_tel_no'            => $validated['house_tel_no'] ?? null,
                'personal_email'          => $validated['personal_email'],
                'bank_account_number'     => $validated['bank_account_number'],
                'bank_name'               => $bankName,
                'epf_no'                  => $validated['epf_no'] ?? null,
                'income_tax_no'           => $validated['income_tax_no'] ?? null,
                'socso_no'                => $validated['socso_no'] ?? null,
                'nric_file_path'          => $nricPath,
                'nric_file_paths'         => $nricPathsJson,
                'invite_staging_json'     => $stagingJson,
                // consent_given_at / consent_ip already set in acceptConsent step
            ]);

            $onboarding->update([
                'invite_submitted' => true,
                'status'           => 'pending',
                'invite_token'     => null,
            ]);
        });

        return redirect()->route('onboarding.invite.success');
    }

    // ── Public: Success page ──────────────────────────────────────────────
    public function success()
    {
        return view('onboarding.invite-success');
    }

    // ── Helper: flush staging JSON to employee relationship tables ────────
    // Called by populateFromOnboarding when employee is activated
    public static function flushStagingToEmployee(Employee $employee, ?string $stagingJson): void
    {
        if (!$stagingJson) return;
        $data = json_decode($stagingJson, true);
        if (!$data) return;

        // Education
        if (!empty($data['education'])) {
            foreach ($data['education'] as $edu) {
                if (empty($edu['qualification'])) continue;
                $certPaths = $edu['certificate_paths'] ?? (isset($edu['certificate_path']) && $edu['certificate_path'] ? [$edu['certificate_path']] : []);
                EmployeeEducationHistory::create([
                    'employee_id'       => $employee->id,
                    'qualification'     => $edu['qualification'],
                    'institution'       => $edu['institution'] ?? null,
                    'year_graduated'    => $edu['year_graduated'] ?? null,
                    'years_experience'  => $edu['years_experience'] ?? null,
                    'certificate_path'  => $certPaths[0] ?? null,
                    'certificate_paths' => !empty($certPaths) ? $certPaths : null,
                ]);
            }
        }

        // Spouses (multiple)
        if (!empty($data['spouses'])) {
            foreach ($data['spouses'] as $sp) {
                if (empty($sp['name'])) continue;
                EmployeeSpouseDetail::create(array_merge(['employee_id' => $employee->id], $sp));
            }
        } elseif (!empty($data['spouse']['name'])) {
            // Legacy single-spouse format from older submissions
            EmployeeSpouseDetail::create(array_merge(['employee_id' => $employee->id], $data['spouse']));
        }

        // Emergency contacts
        if (!empty($data['emergency'])) {
            foreach ($data['emergency'] as $order => $ec) {
                if (empty($ec['name'])) continue;
                EmployeeEmergencyContact::updateOrCreate(
                    ['employee_id' => $employee->id, 'contact_order' => $order],
                    [
                        'name'         => $ec['name'],
                        'tel_no'       => $ec['tel_no'],
                        'relationship' => $ec['relationship'],
                    ]
                );
            }
        }

        // Children
        if (!empty($data['children'])) {
            EmployeeChildRegistration::updateOrCreate(
                ['employee_id' => $employee->id],
                array_merge(['employee_id' => $employee->id], $data['children'])
            );
        }
    }
}