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

        $deactivated = $query->paginate(20)->withQueryString();

        return view('superadmin.account-management', compact('deactivated'));
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
}
