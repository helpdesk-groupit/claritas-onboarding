<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

class TwoFactorController extends Controller
{
    // ── Setup: show QR code ───────────────────────────────────────────────
    public function setup(Request $request)
    {
        $user = $request->user();
        $google2fa = new Google2FA();

        $secret = $google2fa->generateSecretKey();

        // Store encrypted secret temporarily in session until confirmed
        $request->session()->put('2fa_setup_secret', Crypt::encryptString($secret));

        $qrCodeUrl = $google2fa->getQRCodeUrl(
            config('app.name'),
            $user->work_email,
            $secret
        );

        return view('auth.two-factor-setup', [
            'secret'    => $secret,
            'qrCodeUrl' => $qrCodeUrl,
            'qrCodeSvg' => $this->renderQrSvg($qrCodeUrl),
        ]);
    }

    /**
     * Render the otpauth:// URI as an inline SVG QR code.
     *
     * SECURITY — this exists because the setup page used to build its QR with
     * `https://api.qrserver.com/...?data={{ urlencode($qrCodeUrl) }}`, which sends the
     * otpauth URI — and therefore the RAW TOTP SEED plus the account's work email — to
     * a third party on every enrolment. The seed is a permanent shared secret: anyone
     * holding it can mint valid codes forever, so 2FA stops being a second factor. It
     * leaked into that provider's request logs, into any TLS-terminating middlebox on
     * the path, and into the enrolling user's browser history — and it did so worst for
     * the roles where 2FA is MANDATORY (User::TWO_FACTOR_REQUIRED_ROLES).
     *
     * bacon/bacon-qr-code was already a dependency and simply unused. The secret now
     * never leaves this process. Do not reintroduce a remote image service here, and
     * keep `img-src` in SecurityHeaders free of QR hosts.
     *
     * Returns null if rendering fails — the page also prints the secret for manual
     * entry, so a missing image degrades enrolment rather than blocking it.
     */
    private function renderQrSvg(string $otpauthUri): ?string
    {
        try {
            $writer = new \BaconQrCode\Writer(
                new \BaconQrCode\Renderer\ImageRenderer(
                    new \BaconQrCode\Renderer\RendererStyle\RendererStyle(200, 1),
                    new \BaconQrCode\Renderer\Image\SvgImageBackEnd()
                )
            );

            return $writer->writeString($otpauthUri);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    // ── Confirm: verify code and enable 2FA ───────────────────────────────
    public function confirm(Request $request)
    {
        $request->validate(['code' => 'required|digits:6']);

        $encryptedSecret = $request->session()->get('2fa_setup_secret');
        if (!$encryptedSecret) {
            return redirect()->route('profile')->with('error', 'Two-factor setup session expired. Please try again.');
        }

        $secret = Crypt::decryptString($encryptedSecret);
        $google2fa = new Google2FA();

        if (!$google2fa->verifyKey($secret, $request->code, 2)) {
            return back()->withErrors(['code' => 'Invalid verification code. Please try again.']);
        }

        // Generate recovery codes
        $recoveryCodes = collect(range(1, 8))->map(fn () => Str::random(10))->toArray();

        $request->user()->update([
            'two_factor_secret'         => Crypt::encryptString($secret),
            'two_factor_recovery_codes' => Crypt::encryptString(json_encode($recoveryCodes)),
            'two_factor_confirmed_at'   => now(),
        ]);

        $request->session()->forget('2fa_setup_secret');

        return view('auth.two-factor-recovery-codes', [
            'recoveryCodes' => $recoveryCodes,
        ]);
    }

    // ── Disable 2FA ───────────────────────────────────────────────────────
    public function disable(Request $request)
    {
        if ($request->user()->requiresTwoFactor()) {
            return back()->withErrors(['password' => 'Two-factor authentication is required for your role and cannot be disabled.']);
        }

        $request->validate(['password' => 'required']);

        if (!Auth::validate(['work_email' => $request->user()->work_email, 'password' => $request->password])) {
            return back()->withErrors(['password' => 'Incorrect password.']);
        }

        $request->user()->update([
            'two_factor_secret'         => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at'   => null,
        ]);

        // No 2FA → trusted devices are meaningless; clear them.
        \App\Services\TrustedDeviceService::revokeAll($request->user());

        return redirect()->route('profile')->with('success', 'Two-factor authentication has been disabled.');
    }

    // ── Challenge page (shown after password login) ───────────────────────
    public function challenge()
    {
        if (!session('2fa_user_id')) {
            return redirect()->route('login');
        }

        return view('auth.two-factor-challenge');
    }

    // ── Verify challenge ──────────────────────────────────────────────────
    public function verify(Request $request)
    {
        $request->validate([
            'code'          => 'nullable|digits:6',
            'recovery_code' => 'nullable|string',
        ]);

        $userId = session('2fa_user_id');
        if (!$userId) {
            return redirect()->route('login');
        }

        $user = \App\Models\User::findOrFail($userId);
        $secret = Crypt::decryptString($user->two_factor_secret);

        // TOTP code verification (window=2 allows ±60 s clock drift)
        if ($request->filled('code')) {
            $google2fa = new Google2FA();
            if (!$google2fa->verifyKey($secret, $request->code, 2)) {
                return back()->withErrors(['code' => 'Invalid authentication code.']);
            }
        }
        // Recovery code verification
        elseif ($request->filled('recovery_code')) {
            $codes = json_decode(Crypt::decryptString($user->two_factor_recovery_codes), true);
            $index = array_search($request->recovery_code, $codes);

            if ($index === false) {
                return back()->withErrors(['recovery_code' => 'Invalid recovery code.']);
            }

            // Consume the recovery code
            unset($codes[$index]);
            $user->update([
                'two_factor_recovery_codes' => Crypt::encryptString(json_encode(array_values($codes))),
            ]);
        } else {
            return back()->withErrors(['code' => 'Please enter a verification code or recovery code.']);
        }

        // Complete login
        Auth::login($user, session('2fa_remember', false));
        $request->session()->regenerate();

        $token = Str::random(60);
        $user->update(['session_token' => $token, 'login_attempts' => 0]);
        session(['_single_session_token' => $token]);

        // If the user opted to trust this device, mint a trusted-device cookie so
        // future logins from this same device/country skip the 2FA challenge.
        if ($request->boolean('remember_device')) {
            \App\Services\TrustedDeviceService::issue($user, $request);
        }

        // Clean up 2FA session data
        $request->session()->forget(['2fa_user_id', '2fa_remember', '2fa_redirect']);

        $redirect = session('2fa_redirect');
        if ($redirect) {
            return redirect($redirect);
        }

        if ($user->isHr() || $user->isSuperadmin() || $user->isSystemAdmin()) return redirect()->route('hr.dashboard');
        if ($user->isIt()) return redirect()->route('it.dashboard');

        return redirect()->route('user.dashboard');
    }
}
