<?php

namespace Tests\Feature;

use App\Models\TrustedDevice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `two-factor:reset-compromised` — the remediation for the TOTP-seed disclosure fixed on
 * 2026-08-28 (the setup page used to send the otpauth URI, seed included, to
 * api.qrserver.com).
 *
 * This command permanently destroys a second factor for real people on production, so
 * the things worth pinning are its BLAST RADIUS and its dry run: that it touches exactly
 * the roles it claims to, that it takes the trusted-device cookies with it, and that
 * --dry-run genuinely changes nothing.
 */
class ResetCompromisedTwoFactorTest extends TestCase
{
    use RefreshDatabase;

    private function enrolled(string $role): User
    {
        return User::factory()->withTwoFactor()->create(['role' => $role]);
    }

    /** Every mandatory-2FA role is in scope — that is the population whose seed leaked. */
    public function test_it_resets_every_mandatory_two_factor_role(): void
    {
        $users = collect(User::twoFactorRequiredRoles())
            ->mapWithKeys(fn (string $role) => [$role => $this->enrolled($role)]);

        $this->artisan('two-factor:reset-compromised')->assertSuccessful();

        foreach ($users as $role => $user) {
            $user->refresh();
            $this->assertNull($user->two_factor_secret, "{$role} must have been reset.");
            $this->assertNull($user->two_factor_confirmed_at, "{$role} must have been reset.");
            $this->assertNull($user->two_factor_recovery_codes, "{$role} recovery codes must be gone.");
            $this->assertFalse($user->hasTwoFactorEnabled());
        }
    }

    /**
     * A role outside the mandatory set is left alone by default.
     *
     * Their seed leaked too if they enrolled voluntarily, but the default scope is
     * deliberately the mandatory roles; `--all` is the opt-in for the rest. Pinning this
     * is what stops the default quietly becoming "everyone" and knocking out the whole
     * company's 2FA in one command.
     */
    public function test_it_leaves_a_voluntary_enroller_alone_by_default(): void
    {
        $employee = $this->enrolled('employee');

        $this->artisan('two-factor:reset-compromised')->assertSuccessful();

        $employee->refresh();
        $this->assertTrue($employee->hasTwoFactorEnabled(), 'A non-mandatory role must not be reset without --all.');
    }

    /** --all is the documented way to rotate every enrolled account. */
    public function test_the_all_flag_reaches_a_voluntary_enroller(): void
    {
        $employee = $this->enrolled('employee');

        $this->artisan('two-factor:reset-compromised', ['--all' => true])->assertSuccessful();

        $this->assertFalse($employee->refresh()->hasTwoFactorEnabled());
    }

    /**
     * The trusted-device cookies go too.
     *
     * They were minted while the leaked seed was the second factor, so a remembered
     * device would skip the NEW challenge and the rotation would have achieved nothing
     * for exactly the device most likely to be in an attacker's hands.
     */
    public function test_it_revokes_trusted_devices_so_a_remembered_device_cannot_skip_the_new_challenge(): void
    {
        $user = $this->enrolled('superadmin');

        TrustedDevice::create([
            'user_id' => $user->id,
            'selector' => 'sel-'.$user->id,
            'validator_hash' => hash('sha256', 'whatever'),
            'device_label' => 'Test device',
            'user_agent' => 'Mozilla/5.0 Test',
            'last_ip' => '127.0.0.1',
            'last_country' => 'MY',
            'last_used_at' => now(),
            'expires_at' => now()->addDays(30),
        ]);

        $this->assertSame(1, $user->trustedDevices()->count(), 'Pre-condition: the device must exist.');

        $this->artisan('two-factor:reset-compromised')->assertSuccessful();

        $this->assertSame(0, $user->trustedDevices()->count(), 'The remembered device must be revoked with the seed.');
    }

    /** --dry-run reports and changes nothing. A destructive command needs a safe rehearsal. */
    public function test_the_dry_run_changes_nothing(): void
    {
        $user = $this->enrolled('hr_manager');

        $this->artisan('two-factor:reset-compromised', ['--dry-run' => true])
            ->expectsOutputToContain('DRY RUN')
            ->assertSuccessful();

        $this->assertTrue($user->refresh()->hasTwoFactorEnabled(), '--dry-run must not touch anything.');
    }

    /** A user who never enrolled is not "reset" — there is nothing to rotate. */
    public function test_it_ignores_a_user_who_never_enrolled(): void
    {
        $never = User::factory()->create(['role' => 'it_manager']);

        $this->artisan('two-factor:reset-compromised')->assertSuccessful();

        $this->assertNull($never->refresh()->two_factor_secret);
        $this->assertFalse($never->hasTwoFactorEnabled());
    }

    /** --user scopes to one address, for a single re-issue without touching anyone else. */
    public function test_the_user_flag_scopes_to_one_account(): void
    {
        $target = $this->enrolled('it_manager');
        $bystander = $this->enrolled('hr_manager');

        $this->artisan('two-factor:reset-compromised', ['--user' => $target->work_email])
            ->assertSuccessful();

        $this->assertFalse($target->refresh()->hasTwoFactorEnabled());
        $this->assertTrue($bystander->refresh()->hasTwoFactorEnabled(), 'Only the named account may be reset.');
    }

    /**
     * A reset account is pushed into re-enrolment, not locked out.
     *
     * This is the assurance that matters operationally: the person keeps their password
     * and is redirected to set up a new authenticator on their next request.
     */
    public function test_a_reset_user_is_required_to_enrol_again_rather_than_locked_out(): void
    {
        $user = $this->enrolled('hr_manager');

        $this->artisan('two-factor:reset-compromised')->assertSuccessful();
        $user->refresh();

        $this->assertTrue($user->mustSetupTwoFactor(), 'They must be forced back through setup.');

        $this->actingAs($user)->get('/hr/dashboard')->assertRedirect(route('two-factor.setup'));
    }
}
