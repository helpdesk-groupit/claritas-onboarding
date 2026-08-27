<?php

namespace App\Http\Controllers;

use App\Models\SecurityAuditLog;
use App\Models\User;
use App\Models\Employee;
use App\Services\ThreatDetector;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;

class AuthController extends Controller
{
    /**
     * The ONE answer the registration flow gives for every ineligible email.
     *
     * "Not an employee", "already has an account" and "deactivated for a reason that
     * isn't a rehire" are deliberately indistinguishable: told apart, this
     * unauthenticated endpoint enumerates the staff directory AND reports which
     * accounts are in a state the rehire path would accept.
     */
    private const REGISTER_INELIGIBLE = 'This email cannot be registered. If you already have an account, please sign in or use "Forgot password". Otherwise contact the IT team.';

    /**
     * May this EXISTING user row be re-claimed by someone completing self-registration?
     *
     * Only for a genuine rehire: an account deactivated because the person LEFT
     * (`exit_date`, stamped by the exit-date safety net in login() or by HR), who now
     * has a current employment row again because they were re-onboarded.
     *
     * SECURITY — this predicate is the fix for an unauthenticated account-takeover.
     * It used to be `! $user->is_active && $hasCurrentActiveEmployee`, which an
     * attacker could satisfy against ANY currently-employed person: five wrong
     * passwords deactivate the account with `deactivation_reason = 'login_lockout'`
     * (see login()), and a current employee has `active_until IS NULL` by definition —
     * so register() would then overwrite the password with one the attacker chose and
     * reactivate the account. Pinning the reason to 'exit_date' closes that, because
     * an attacker has no way to produce that reason: the exit-date path in login()
     * also stamps `active_until`, which makes hasCurrentActiveEmployee() false unless
     * HR has since re-onboarded the person — the legitimate rehire, and nothing else.
     *
     * Deliberately NOT reachable for 'login_lockout': clearing a lockout is a
     * superadmin action (AccountManagementController::activate), never a self-service
     * one, and certainly not one that rewrites the password on the way through.
     */
    private static function rehireEligible(User $user, string $email): bool
    {
        if ($user->is_active) {
            return false;
        }

        if ($user->deactivation_reason !== 'exit_date') {
            return false;
        }

        return Employee::where('company_email', $email)
            ->whereNull('active_until')
            ->exists();
    }

    // ── Login ──────────────────────────────────────────────────────────────
    public function showLogin(Request $request)
    {
        // Store redirect intent in session so it survives through login POST
        if ($request->query('redirect') === 'profile-consent') {
            $request->session()->put('redirect_after_login', 'profile-consent');
        }
        return view('auth.login', ['redirectIntent' => $request->query('redirect')]);
    }

    public function login(Request $request)
    {
        $request->validate([
            'work_email' => 'required|email',
            'password'   => 'required',
        ]);

        $user = User::where('work_email', $request->work_email)->first();

        // Unified credential check — uses a generic error message for ALL failure cases
        // to prevent user enumeration (OWASP A07 — Identification & Authentication Failures)
        $genericError = 'The provided credentials do not match our records.';

        // Check deactivated account first (before password check)
        if ($user && !$user->is_active) {
            // Still perform a dummy hash check to prevent timing-based enumeration
            Hash::check($request->password, $user->password ?? '$2y$12$dummyhashvaluefortimingatk000000000000000000000');
            $ctx = [
                'user_id'    => $user->id,
                'work_email' => $user->work_email,
                'role'       => $user->role,
                'ip_address' => $request->ip(),
                'details'    => 'Login attempt on deactivated account (reason: ' . ($user->deactivation_reason ?? 'unknown') . ').',
            ];
            SecurityAuditLog::record('failed_login', $ctx);
            ThreatDetector::analyze('failed_login', $ctx);
            return back()->withErrors(['work_email' => $genericError])->onlyInput('work_email');
        }

        if (!$user || !Hash::check($request->password, $user->password ?? '$2y$12$dummyhashvaluefortimingatk000000000000000000000')) {
            // Track failed attempts per user
            if ($user) {
                $attempts = $user->login_attempts + 1;
                if ($attempts >= 5) {
                    $user->update([
                        'login_attempts'      => $attempts,
                        'is_active'           => false,
                        'deactivation_reason' => 'login_lockout',
                        'deactivated_at'      => now(),
                    ]);
                    $lockCtx = [
                        'user_id'    => $user->id,
                        'work_email' => $user->work_email,
                        'role'       => $user->role,
                        'ip_address' => $request->ip(),
                        'details'    => "Account locked after {$attempts} consecutive failed login attempts.",
                    ];
                    SecurityAuditLog::record('lockout', $lockCtx);
                    ThreatDetector::analyze('account_locked', $lockCtx);
                    return back()->withErrors([
                        'work_email' => $genericError,
                    ])->onlyInput('work_email');
                }
                $user->update(['login_attempts' => $attempts]);
                $failCtx = [
                    'user_id'    => $user->id,
                    'work_email' => $user->work_email,
                    'role'       => $user->role,
                    'ip_address' => $request->ip(),
                    'details'    => "Failed login attempt {$attempts}/5.",
                ];
                SecurityAuditLog::record('failed_login', $failCtx);
                ThreatDetector::analyze('failed_login', $failCtx);
            } else {
                $unknownCtx = [
                    'work_email' => $request->work_email,
                    'ip_address' => $request->ip(),
                    'details'    => 'Login attempt with unknown email.',
                ];
                SecurityAuditLog::record('failed_login', $unknownCtx);
                ThreatDetector::analyze('failed_login', $unknownCtx);
            }
            return back()->withErrors([
                'work_email' => $genericError,
            ])->onlyInput('work_email');
        }

        // Safety net: if the user's CURRENT employment relationship has passed
        // its exit date, deactivate and block. Filter on active_until=NULL so a
        // rehire (who carries an old offboarded Employee row alongside a new
        // active one) is evaluated against their current row, not the historical
        // one. Without this filter, hasOne returns whichever row Eloquent picks
        // first — typically the oldest, offboarded one — and locks the rehire
        // out with deactivation_reason='exit_date'.
        $linkedEmployee = $user->employee()->whereNull('active_until')->first();
        if ($linkedEmployee && $linkedEmployee->exit_date && $linkedEmployee->exit_date->isPast()) {
            $user->update(['is_active' => false, 'deactivation_reason' => 'exit_date', 'deactivated_at' => now()]);
            $linkedEmployee->update(['active_until' => $linkedEmployee->exit_date]);
            return back()->withErrors([
                'work_email' => $genericError,
            ])->onlyInput('work_email');
        }

        // Successful login — reset failed attempt counter (final reset after 2FA if enabled)

        // If user has 2FA enabled, redirect to challenge page before completing login —
        // UNLESS this is a trusted device (remembered, same device family, same country).
        // Trusted-device skip is additive: any failure falls through to the challenge.
        if ($user->hasTwoFactorEnabled()
            && !\App\Services\TrustedDeviceService::trusts($request, $user)) {
            $request->session()->put('2fa_user_id', $user->id);
            $request->session()->put('2fa_remember', $request->boolean('remember'));
            if ($request->input('redirect') === 'profile-consent' || $request->session()->get('redirect_after_login') === 'profile-consent') {
                $request->session()->put('2fa_redirect', route('profile'));
            }
            return redirect()->route('two-factor.challenge');
        }

        $user->update(['login_attempts' => 0]);

        Auth::login($user, $request->boolean('remember'));
        $request->session()->regenerate();

        // Single-session enforcement: generate a unique token, store in DB and session.
        // Any previous session no longer holds this token → gets kicked out on next request.
        $token = \Illuminate\Support\Str::random(60);
        $user->update(['session_token' => $token]);
        session(['_single_session_token' => $token]);


        // If arriving from a consent re-acknowledgement email, redirect to profile
        if ($request->input('redirect') === 'profile-consent' || $request->session()->get('redirect_after_login') === 'profile-consent') {
            $request->session()->forget('redirect_after_login');
            return redirect()->route('profile');
        }

        if ($user->isHr() || $user->isSuperadmin() || $user->isSystemAdmin()) return redirect()->route('hr.dashboard');
        if ($user->isIt()) return redirect()->route('it.dashboard');

        return redirect()->route('user.dashboard');
    }

    // ── Register Step 1: show email form ───────────────────────────────────
    public function showRegister()
    {
        return view('auth.register');
    }

    // ── Register Step 1: validate email exists in employees/work_details ───
    public function checkEmail(Request $request)
    {
        $request->validate(['work_email' => 'required|email']);

        $email = $request->work_email;

        // Check if email exists in work_details (company_email) or employees table
        $inWorkDetails = \App\Models\WorkDetail::where('company_email', $email)->exists();
        $inEmployees   = Employee::where('company_email', $email)->exists();

        if (!$inWorkDetails && !$inEmployees) {
            return back()
                ->withInput()
                ->withErrors(['work_email' => self::REGISTER_INELIGIBLE]);
        }

        // Block when an account already exists, and when a deactivated account is
        // NOT an eligible rehire. A rehire (previously offboarded, now returning with a
        // fresh Onboarding) is allowed to proceed — register() reactivates it instead of
        // creating a duplicate. The offboarding history stays intact on the old Employee
        // row; only the User row is reactivated.
        //
        // SECURITY: eligibility is decided by the SAME predicate register() uses
        // (rehireEligible), so this page can never advertise a path register() will
        // refuse — and, critically, can never advertise one for an account an attacker
        // put into a deactivated state themselves by burning login attempts.
        $existing = User::where('work_email', $email)->first();
        if ($existing && ! self::rehireEligible($existing, $email)) {
            // One message for BOTH "no such employee" and "already registered".
            // Distinguishing them turns this unauthenticated endpoint into an
            // employee-directory oracle (OWASP A07), which is exactly what the login
            // and forgot-password handlers already go out of their way to avoid.
            return back()
                ->withInput()
                ->withErrors(['work_email' => self::REGISTER_INELIGIBLE]);
        }

        // Email is valid and either has no account yet, or has a deactivated
        // account that's eligible for rehire reactivation. Pass to step 2.
        //
        // put(), not with(): flash data survives exactly one request, so it would be
        // gone by the time the set-password form is POSTed back — and it must still be
        // there, because register() verifies this step actually happened.
        $request->session()->put('verified_email', $email);

        return redirect()->route('register.setPassword');
    }

    // ── Register Step 2: show set-password form ────────────────────────────
    public function showSetPassword(Request $request)
    {
        // Must arrive via the email check redirect (session flash)
        if (!session('verified_email')) {
            return redirect()->route('register')
                ->withErrors(['work_email' => 'Please verify your work email first.']);
        }

        return view('auth.set-password', [
            'verified_email' => session('verified_email'),
        ]);
    }

    // ── Register Step 2: create the account ───────────────────────────────
    public function register(Request $request)
    {
        $request->validate([
            'work_email' => 'required|email',
            'password'   => [
                'required',
                'confirmed',
                'min:8',
                'regex:/[0-9]/',       // at least one number
                'regex:/[^A-Za-z0-9]/' // at least one symbol
            ],
        ], [
            'password.regex' => 'Password must contain at least one number and one symbol (e.g. @, #, !).',
            'password.min'   => 'Password must be at least 8 characters.',
        ]);

        // Re-check email validity (in case someone posts directly)
        $email = $request->work_email;

        // Step 1 must actually have happened, for THIS address. checkEmail() persists
        // the address it cleared; without this the set-password POST stands entirely on
        // its own and step 1 is decorative. Compared case-insensitively because
        // checkEmail() stores what the user typed.
        if (! hash_equals(
            mb_strtolower((string) $request->session()->get('verified_email')),
            mb_strtolower((string) $email)
        )) {
            return redirect()->route('register')
                ->withErrors(['work_email' => 'Please verify your work email first.']);
        }

        $inWorkDetails = \App\Models\WorkDetail::where('company_email', $email)->exists();
        $inEmployees   = Employee::where('company_email', $email)->exists();

        if (!$inWorkDetails && !$inEmployees) {
            return redirect()->route('register')
                ->withErrors(['work_email' => 'This email is not valid. Please start again.']);
        }

        // Rehire-aware: a returning employee re-enrolling reactivates their existing
        // row and sets a new password rather than creating a duplicate. What counts as
        // a rehire is decided by rehireEligible() — read its docblock before widening
        // it, because loosening this test is an unauthenticated account takeover of
        // every currently-employed person, not a UX improvement. Every other
        // deactivation reason (notably 'login_lockout') falls through to the generic
        // refusal below; clearing those is a superadmin action.
        $existingUser = User::where('work_email', $email)->first();
        if ($existingUser) {
            if (self::rehireEligible($existingUser, $email)) {
                $request->session()->forget('verified_email');
                $existingUser->update([
                    'password'            => Hash::make($request->password),
                    'is_active'           => true,
                    'login_attempts'      => 0,
                    'deactivation_reason' => null,
                    'deactivated_at'      => null,
                ]);
                // Link the freshly active Employee row(s) to this user if not yet linked
                Employee::where('company_email', $email)
                    ->whereNull('active_until')
                    ->whereNull('user_id')
                    ->update(['user_id' => $existingUser->id]);

                $loginRoute = route('login');
                if (session('redirect_after_login') === 'profile-consent') {
                    $loginRoute .= '?redirect=profile-consent';
                }
                return redirect($loginRoute)
                    ->with('success', 'Account reactivated successfully! Please log in.');
            }

            return redirect()->route('login')
                ->withErrors(['work_email' => 'An account already exists for this email.']);
        }

        // Determine role from work_details if available
        $workDetail = \App\Models\WorkDetail::where('company_email', $email)->first();
        $role = $workDetail?->role ?? 'employee';

        // Map work_details role to User role (only HR/IT roles count; others get 'employee')
        $allowedRoles = [
            'hr_manager','hr_executive','hr_intern',
            'it_manager','it_executive','it_intern',
            'superadmin','system_admin',
        ];
        if (!in_array($role, $allowedRoles)) {
            $role = 'employee';
        }

        // Get full name from onboarding personal details if available
        $name = null;
        if ($workDetail) {
            $personal = \App\Models\PersonalDetail::where('onboarding_id', $workDetail->onboarding_id)->first();
            $name = $personal?->full_name;
        }
        if (!$name) {
            $emp = Employee::where('company_email', $email)->first();
            $name = $emp?->full_name;
        }
        $name = $name ?? explode('@', $email)[0];

        // Create the user account. The step-1 marker is spent here so a single cleared
        // address cannot be replayed into a second registration attempt.
        $request->session()->forget('verified_email');

        $user = User::create([
            'name'       => $name,
            'work_email' => $email,
            'password'   => Hash::make($request->password),
            'role'       => $role,
            'is_active'  => true,
        ]);

        // Link to employee record if one exists.
        // For non-system roles, default work_role to 'others' until superadmin assigns one.
        Employee::where('company_email', $email)
            ->whereNull('user_id')
            ->update(['user_id' => $user->id]);

        if ($role === 'employee') {
            Employee::where('company_email', $email)
                ->whereNull('work_role')
                ->update(['work_role' => 'others']);
        }

        // If consent-redirect was stored in session, carry it through to login page
        $loginRoute = route('login');
        if (session('redirect_after_login') === 'profile-consent') {
            $loginRoute .= '?redirect=profile-consent';
        }
        return redirect($loginRoute)
            ->with('success', 'Account created successfully! Please log in.');
    }

    // ── Forgot Password ────────────────────────────────────────────────────
    public function showForgotPassword()
    {
        return view('auth.forgot-password');
    }

    public function sendResetLink(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        // Always return the same generic message to prevent user enumeration
        // (OWASP A07 — Identification & Authentication Failures)
        $genericMessage = 'If an account exists with that email address, a password reset link has been sent.';

        $user = User::where('work_email', $request->email)->first();

        // Silently bail for non-existent or deactivated accounts
        if (!$user || !$user->is_active) {
            return back()->with('status', $genericMessage);
        }

        // Always show the generic message regardless of actual send result
        Password::sendResetLink(['work_email' => $request->email]);

        return back()->with('status', $genericMessage);
    }

    public function showResetPassword(Request $request, string $token)
    {
        return view('auth.reset-password', ['token' => $token, 'email' => $request->email]);
    }

    public function resetPassword(Request $request)
    {
        $request->validate([
            'token'    => 'required',
            'email'    => 'required|email',
            'password' => 'required|min:8|confirmed',
        ]);

        $status = Password::reset(
            [
                'work_email'            => $request->email,
                'password'              => $request->password,
                'password_confirmation' => $request->password_confirmation,
                'token'                 => $request->token,
            ],
            function (User $user, string $password) {
                $user->forceFill(['password' => Hash::make($password)])->save();
                // A password reset may follow a compromise — kill all remembered
                // devices so an attacker's trusted cookie can no longer skip 2FA.
                \App\Services\TrustedDeviceService::revokeAll($user);
                event(new \Illuminate\Auth\Events\PasswordReset($user));
            }
        );

        return $status === Password::PASSWORD_RESET
            ? redirect()->route('login')->with('status', 'Password reset successfully! Please login.')
            : back()->withErrors(['email' => [__($status)]]);
    }

    // ── Logout ─────────────────────────────────────────────────────────────
    public function logout(Request $request)
    {
        // Clear the stored session token so the user's slot is freed
        if (Auth::check()) {
            Auth::user()->update(['session_token' => null]);
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect()->route('login');
    }
}