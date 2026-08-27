<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\TrustedDeviceService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Force 2FA re-enrolment after the TOTP-seed disclosure.
 *
 * WHY THIS EXISTS — until 2026-08-28 the 2FA setup page rendered its QR with
 * `https://api.qrserver.com/...?data={otpauth URI}`, which sent the RAW TOTP SEED and
 * the account's work email to a third party on every enrolment. A TOTP seed is a
 * permanent shared secret: anyone who read that request (the provider's logs, any
 * TLS-terminating middlebox on the path, the enrolling user's own browser history) can
 * mint valid codes forever. Fixing the page stops NEW leaks; it cannot un-leak the
 * seeds already enrolled, so every one of them has to be replaced.
 *
 * SCOPE — defaults to `User::TWO_FACTOR_REQUIRED_ROLES`, the roles for which 2FA is
 * mandatory and therefore the accounts whose seeds are both certainly present and worth
 * the most. `--all` widens it to every enrolled user regardless of role; use that if you
 * would rather rotate everything.
 *
 * ORDER MATTERS — run this only AFTER the fixed code is deployed. Against the old code
 * a re-enrolment sends the replacement seed straight back to the same third party, which
 * would make this command actively harmful.
 *
 * Mirrors AccountManagementController::resetTwoFactor() exactly, including the
 * trusted-device revocation: those cookies were issued on the strength of the
 * compromised seed, so leaving them would let a remembered device skip the new
 * challenge. Behaviour is deliberately identical so the bulk path and the per-user
 * button can never drift.
 *
 * Nobody is locked out by this. `EnforceTwoFactor` redirects a user with
 * `mustSetupTwoFactor()` to the setup page, so the next login is: password → set up 2FA
 * again → continue. The accounts are password-only for the window between the reset and
 * that re-enrolment, which is the accepted trade against a seed a stranger may hold.
 */
class ResetCompromisedTwoFactor extends Command
{
    protected $signature = 'two-factor:reset-compromised
                            {--dry-run : List who would be reset and change nothing}
                            {--all : Every enrolled user, not just the mandatory-2FA roles}
                            {--user= : Reset one work_email only}';

    protected $description = 'Force 2FA re-enrolment for accounts whose TOTP seed was exposed to the third-party QR service (see the 2026-08-28 security review). Deploy the fix FIRST.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $query = User::whereNotNull('two_factor_secret')
            ->whereNotNull('two_factor_confirmed_at');

        if ($single = $this->option('user')) {
            $query->where('work_email', $single);
        } elseif (! $this->option('all')) {
            $query->whereIn('role', User::twoFactorRequiredRoles());
        }

        $users = $query->orderBy('role')->orderBy('work_email')->get();

        if ($users->isEmpty()) {
            $this->info('No enrolled accounts matched — nothing to reset.');

            return self::SUCCESS;
        }

        $this->line('');
        $this->line($dryRun
            ? "DRY RUN — {$users->count()} account(s) WOULD be reset:"
            : "Resetting 2FA for {$users->count()} account(s):");
        $this->line('');

        $this->table(
            ['Role', 'Work email', 'Name', 'Enrolled at', 'Trusted devices'],
            $users->map(fn (User $u) => [
                $u->role,
                $u->work_email,
                $u->name,
                optional($u->two_factor_confirmed_at)->format('d-m-Y H:i') ?? '—',
                $u->trustedDevices()->count(),
            ])->all()
        );

        if ($dryRun) {
            $this->line('');
            $this->comment('Nothing was changed. Re-run without --dry-run to apply.');

            return self::SUCCESS;
        }

        $reset = 0;

        foreach ($users as $user) {
            // Same three columns the per-user superadmin control clears. Written
            // straight rather than through a model event so a reset can never be
            // silently skipped by an observer.
            $user->update([
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
            ]);

            // The remembered-device cookies were minted while the leaked seed was the
            // second factor. Leaving them lets that device skip the new challenge
            // entirely, which would defeat the point of rotating the seed.
            TrustedDeviceService::revokeAll($user);

            $reset++;

            Log::warning('2FA reset — forced re-enrolment after TOTP seed disclosure', [
                'user_id' => $user->id,
                'work_email' => $user->work_email,
                'role' => $user->role,
            ]);

            $this->line("  reset  {$user->work_email}");
        }

        $this->line('');
        $this->info("Done — {$reset} account(s) reset. They keep their password and will be");
        $this->info('required to enrol a NEW authenticator on their next login.');
        $this->line('');
        $this->warn('Tell these people before they next sign in: their authenticator app will');
        $this->warn('stop working and they must scan a fresh QR. Delete the old entry in the app.');

        return self::SUCCESS;
    }
}
