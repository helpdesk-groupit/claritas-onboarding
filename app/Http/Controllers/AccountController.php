<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;

class AccountController extends Controller
{
    public function show()
    {
        return view('user.account');
    }

    // ── Change Password: log out → redirect to Reset Password page ────────
    public function changePassword(Request $request)
    {
        $user  = Auth::user();
        $email = $user->work_email;

        // Log out first
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // Redirect to forgot-password page (titled "Reset Password")
        // Pre-fill the email via session so the user doesn't have to type it
        return redirect()->route('password.request')
            ->with('prefill_email', $email);
    }

    // ── Profile picture upload ────────────────────────────────────────────
    public function uploadProfilePicture(Request $request)
    {
        $request->validate([
            'profile_picture' => 'required|image|mimes:jpeg,png,jpg,gif,webp|max:2048',
        ]);

        $user = Auth::user();
        $path = $request->file('profile_picture')->store('profile-pictures', 'public');

        if ($user->profile_picture) {
            \Illuminate\Support\Facades\Storage::disk('public')->delete($user->profile_picture);
        }

        $user->update(['profile_picture' => $path]);

        return back()->with('avatar_success', 'Profile picture updated successfully.');
    }

    public function setLanguage(Request $request)
    {
        $request->validate(['locale' => 'required|in:en,ms']);
        session(['locale' => $request->locale]);
        return response()->json(['ok' => true]);
    }
}