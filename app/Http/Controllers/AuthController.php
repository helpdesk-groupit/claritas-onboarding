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

        // Safety net: if linked employee has passed their exit date, deactivate and block
        $linkedEmployee = $user->employee;
        if ($linkedEmployee && $linkedEmployee->exit_date && $linkedEmployee->exit_date->isPast()) {
            $user->update(['is_active' => false, 'deactivation_reason' => 'exit_date', 'deactivated_at' => now()]);
            if (!$linkedEmployee->active_until) {
                $linkedEmployee->update(['active_until' => $linkedEmployee->exit_date]);
            }
            return back()->withErrors([
                'work_email' => $genericError,
            ])->onlyInput('work_email');
        }

        // Successful login — reset failed attempt counter
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
                ->withErrors(['work_email' => 'This email does not exist in our system. Please enter your assigned work email or contact the IT team.']);
        }

        // Check if an account already exists for this email
        if (User::where('work_email', $email)->exists()) {
            return back()
                ->withInput()
                ->withErrors(['work_email' => 'An account with this email already exists. Please sign in instead.']);
        }

        // Email is valid and has no account yet — pass to step 2
        return redirect()->route('register.setPassword')
            ->with('verified_email', $email);
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
        $inWorkDetails = \App\Models\WorkDetail::where('company_email', $email)->exists();
        $inEmployees   = Employee::where('company_email', $email)->exists();

        if (!$inWorkDetails && !$inEmployees) {
            return redirect()->route('register')
                ->withErrors(['work_email' => 'This email is not valid. Please start again.']);
        }

        if (User::where('work_email', $email)->exists()) {
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

        // Create the user account
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