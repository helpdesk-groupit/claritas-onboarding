<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AccountManagementController extends Controller
{
    private function authorizeAdmin(): void
    {
        if (!Auth::user()->isSuperadmin() && !Auth::user()->isSystemAdmin()) {
            abort(403);
        }
    }

    public function index(Request $request)
    {
        $this->authorizeAdmin();

        $query = User::with('employee')
            ->where('is_active', false)
            ->where('deactivation_reason', 'login_lockout')
            ->orderByDesc('deactivated_at');

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('name', 'like', "%{$s}%")
                  ->orWhere('work_email', 'like', "%{$s}%");
            });
        }

        $deactivated = $query->paginate(20, ['*'], 'deactivated_page')->withQueryString();

        // 2FA-enabled users — admin can reset their 2FA if they're locked out
        $tfaQuery = User::with('employee')
            ->whereNotNull('two_factor_secret')
            ->whereNotNull('two_factor_confirmed_at')
            ->orderBy('name');

        if ($request->filled('tfa_search')) {
            $s = $request->tfa_search;
            $tfaQuery->where(function ($q) use ($s) {
                $q->where('name', 'like', "%{$s}%")
                  ->orWhere('work_email', 'like', "%{$s}%");
            });
        }

        $tfaUsers = $tfaQuery->paginate(20, ['*'], 'tfa_page')->withQueryString();

        return view('superadmin.account-management', compact('deactivated', 'tfaUsers'));
    }

    public function activate(User $user)
    {
        $this->authorizeAdmin();

        $user->update([
            'is_active'           => true,
            'login_attempts'      => 0,
            'deactivation_reason' => null,
            'deactivated_at'      => null,
        ]);

        return back()->with('success', 'Account for ' . $user->name . ' (' . $user->work_email . ') has been activated.');
    }

    public function resetTwoFactor(User $user)
    {
        $this->authorizeAdmin();

        if (!$user->hasTwoFactorEnabled()) {
            return back()->with('error', 'This user does not have two-factor authentication enabled.');
        }

        $user->update([
            'two_factor_secret'         => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at'   => null,
        ]);

        // Reset implies the user may be locked out / compromised — drop any
        // remembered devices so a stale trusted cookie can't skip the new 2FA.
        \App\Services\TrustedDeviceService::revokeAll($user);

        return back()->with('success', 'Two-factor authentication has been reset for ' . $user->name . ' (' . $user->work_email . '). They will be prompted to set up 2FA again on next login.');
    }
}
