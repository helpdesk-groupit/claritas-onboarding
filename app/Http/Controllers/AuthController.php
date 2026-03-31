<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Employee;
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

        if (!$user || !Hash::check($request->password, $user->password)) {
            return back()->withErrors([
                'work_email' => 'Invalid credentials. Please try again.',
            ])->onlyInput('work_email');
        }

        if (!$user->is_active) {
            return back()->withErrors([
                'work_email' => 'This account has been deactivated.',
            ])->onlyInput('work_email');
        }

        // Safety net: if linked employee has passed their exit date, deactivate and block
        $linkedEmployee = $user->employee;
        if ($linkedEmployee && $linkedEmployee->exit_date && $linkedEmployee->exit_date->isPast()) {
            $user->update(['is_active' => false]);
            if (!$linkedEmployee->active_until) {
                $linkedEmployee->update(['active_until' => $linkedEmployee->exit_date]);
            }
            return back()->withErrors([
                'work_email' => 'Your account access ended on ' . $linkedEmployee->exit_date->format('d M Y') . '.',
            ])->onlyInput('work_email');
        }

        Auth::login($user, $request->boolean('remember'));
        $request->session()->regenerate();


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

        // Link to employee record if one exists
        Employee::where('company_email', $email)
            ->whereNull('user_id')
            ->update(['user_id' => $user->id]);

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

        $user = User::where('work_email', $request->email)->first();
        if (!$user) {
            return back()->withErrors(['email' => 'No account found with that email address.']);
        }

        $status = Password::sendResetLink(['work_email' => $request->email]);

        return $status === Password::RESET_LINK_SENT
            ? back()->with('status', 'A password reset link has been sent to ' . $request->email . '.')
            : back()->withErrors(['email' => 'Failed to send reset link. Please try again.']);
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
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect()->route('login');
    }
}