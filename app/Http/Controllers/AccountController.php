<?php

namespace App\Http\Controllers;

use App\Models\TrustedDevice;
use App\Services\TrustedDeviceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;

class AccountController extends Controller
{
    public function show(Request $request)
    {
        // Active (non-expired) remembered devices, most-recently-used first.
        $trustedDevices = Auth::user()->trustedDevices()
            ->where('expires_at', '>', now())
            ->orderByDesc('last_used_at')
            ->get();

        // Mark the row matching the current browser's cookie so the UI can label it.
        $currentSelector = null;
        $raw = $request->cookie(TrustedDeviceService::cookieName(Auth::id()));
        if (is_string($raw) && str_contains($raw, ':')) {
            $currentSelector = explode(':', $raw, 2)[0];
        }

        return view('user.account', compact('trustedDevices', 'currentSelector'));
    }

    // ── Trusted devices: revoke one ───────────────────────────────────────
    public function revokeTrustedDevice(Request $request, TrustedDevice $device)
    {
        abort_unless($device->user_id === Auth::id(), 403);

        TrustedDeviceService::revoke($device, $request);

        return back()->with('success', 'That device has been signed out of trusted status and will require 2FA next time.');
    }

    // ── Trusted devices: revoke all ───────────────────────────────────────
    public function revokeAllTrustedDevices(Request $request)
    {
        TrustedDeviceService::revokeAll(Auth::user());
        \Illuminate\Support\Facades\Cookie::queue(
            \Illuminate\Support\Facades\Cookie::forget(TrustedDeviceService::cookieName(Auth::id()))
        );

        return back()->with('success', 'All trusted devices have been removed. 2FA will be required on every device at next login.');
    }

    // ── Change Password: log out → redirect to Reset Password page ────────
    public function changePassword(Request $request)
    {
        $user = Auth::user();
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
            'profile_picture' => 'required|image|mimes:jpeg,png,jpg,gif,webp|max:2048|valid_file_content',
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
