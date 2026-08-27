<?php

namespace Tests\Feature;

use App\Http\Middleware\EnforceTwoFactor;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\OvertimeRequest;
use App\Models\PersonalDetail;
use App\Models\User;
use App\Models\WorkDetail;
use App\Models\WorkSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Regression tests for the findings of the 2026-08-28 whole-project security review.
 *
 * Each test below fails against the code as it stood before that review. They are grouped
 * by finding, and the docblocks say what the failure MEANT rather than only what the code
 * did — the point of pinning these is that the next person to touch the registration flow,
 * the attendance controller or an export understands what they would be re-opening.
 */
class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(EnforceTwoFactor::class);
        Mail::fake();
    }

    /**
     * A currently-employed person with a working account.
     *
     * Both halves matter: the User row is what gets taken over, and the Employee row with
     * active_until = NULL is what the old rehire predicate keyed on.
     */
    private function activeStaff(string $email, string $role = 'employee'): array
    {
        $user = User::factory()->create([
            'work_email' => $email,
            'role' => $role,
            'password' => Hash::make('CorrectHorse1!'),
            'is_active' => true,
        ]);

        $employee = Employee::factory()->create([
            'company_email' => $email,
            'user_id' => $user->id,
            'active_until' => null,
        ]);

        return [$user, $employee];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Finding 1 — unauthenticated account takeover via the registration flow
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * The headline vulnerability: lock an account out, then re-register it.
     *
     * Five wrong passwords deactivate an account with deactivation_reason='login_lockout'.
     * The old rehire branch accepted ANY deactivated user who still had a current employment
     * row — which every employed person has — so a POST to /register then overwrote the
     * password with one the attacker chose and reactivated the account. Entirely
     * unauthenticated, and it also bypassed the superadmin-only unlock.
     */
    public function test_a_locked_out_account_cannot_be_re_registered_with_a_new_password(): void
    {
        [$victim] = $this->activeStaff('victim@claritas.test');

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['work_email' => 'victim@claritas.test', 'password' => 'wrong'.$i]);
        }

        $victim->refresh();
        $this->assertFalse((bool) $victim->is_active, 'Pre-condition: the lockout must actually have fired.');
        $this->assertSame('login_lockout', $victim->deactivation_reason);

        // The takeover attempt.
        $this->post('/register', [
            'work_email' => 'victim@claritas.test',
            'password' => 'Attacker1!',
            'password_confirmation' => 'Attacker1!',
        ]);

        $victim->refresh();
        $this->assertFalse(
            Hash::check('Attacker1!', $victim->password),
            'The attacker\'s password was accepted — this is the account takeover.'
        );
        $this->assertTrue(
            Hash::check('CorrectHorse1!', $victim->password),
            'The real password must survive untouched.'
        );
        $this->assertFalse((bool) $victim->is_active, 'A locked account must NOT be self-reactivated.');
        $this->assertSame('login_lockout', $victim->deactivation_reason);
    }

    /**
     * The takeover is refused by rehireEligible() ITSELF, not merely by the session gate.
     *
     * The test above POSTs /register cold, so it is stopped by the "step 1 must have
     * happened" check. That is one of two independent layers, and it is the SHALLOWER
     * one — a future change that relaxes or removes the session marker would silently
     * re-open the account takeover if this deeper check were not also pinned. Here the
     * step-1 marker is seeded directly, so the ONLY thing standing between the attacker
     * and the victim's password is the deactivation_reason test.
     */
    public function test_the_rehire_branch_itself_refuses_a_lockout_even_with_step_one_satisfied(): void
    {
        [$victim] = $this->activeStaff('deep@claritas.test');
        $victim->update([
            'is_active' => false,
            'deactivation_reason' => 'login_lockout',
            'deactivated_at' => now(),
        ]);

        $this->withSession(['verified_email' => 'deep@claritas.test'])
            ->post('/register', [
                'work_email' => 'deep@claritas.test',
                'password' => 'Attacker1!',
                'password_confirmation' => 'Attacker1!',
            ]);

        $victim->refresh();
        $this->assertFalse(
            Hash::check('Attacker1!', $victim->password),
            'rehireEligible() must refuse a login_lockout account on its own merits.'
        );
        $this->assertTrue(Hash::check('CorrectHorse1!', $victim->password));
        $this->assertFalse((bool) $victim->is_active);
    }

    /**
     * The step-1 page must not advertise a path step 2 will refuse.
     *
     * checkEmail() decided eligibility with its own looser test, so it happily sent an
     * attacker on to the set-password form for a locked-out account — telling them the
     * takeover was available before they tried it.
     */
    public function test_the_email_check_does_not_offer_a_locked_out_account_a_way_in(): void
    {
        [$victim] = $this->activeStaff('locked@claritas.test');
        $victim->update(['is_active' => false, 'deactivation_reason' => 'login_lockout', 'deactivated_at' => now()]);

        $response = $this->post('/register/check-email', ['work_email' => 'locked@claritas.test']);

        $response->assertSessionHasErrors('work_email');
        $this->assertNull(session('verified_email'), 'No address may be cleared for step 2 here.');
    }

    /**
     * A GENUINE rehire still works — the fix must not close the door it was built for.
     *
     * Someone who left (deactivation_reason='exit_date') and has since been re-onboarded,
     * so they have a current employment row again, re-enrols and sets a new password.
     */
    public function test_a_genuine_rehire_can_still_re_enrol(): void
    {
        [$user] = $this->activeStaff('rehire@claritas.test');
        $user->update(['is_active' => false, 'deactivation_reason' => 'exit_date', 'deactivated_at' => now()]);

        $this->post('/register/check-email', ['work_email' => 'rehire@claritas.test'])
            ->assertRedirect(route('register.setPassword'));

        $this->post('/register', [
            'work_email' => 'rehire@claritas.test',
            'password' => 'Returning1!',
            'password_confirmation' => 'Returning1!',
        ])->assertRedirect();

        $user->refresh();
        $this->assertTrue((bool) $user->is_active, 'A real rehire must be reactivated.');
        $this->assertTrue(Hash::check('Returning1!', $user->password));
        $this->assertNull($user->deactivation_reason);
    }

    /**
     * A brand-new hire's FIRST registration still works.
     *
     * The session gate added to register() must not break the ordinary two-step flow.
     */
    public function test_a_first_time_registration_still_works_through_the_two_steps(): void
    {
        Employee::factory()->create(['company_email' => 'newhire@claritas.test', 'active_until' => null]);

        $this->post('/register/check-email', ['work_email' => 'newhire@claritas.test'])
            ->assertRedirect(route('register.setPassword'));

        $this->get('/register/set-password')->assertOk();

        $this->post('/register', [
            'work_email' => 'newhire@claritas.test',
            'password' => 'FirstTime1!',
            'password_confirmation' => 'FirstTime1!',
        ])->assertRedirect();

        $created = User::where('work_email', 'newhire@claritas.test')->first();
        $this->assertNotNull($created, 'The new hire must still get an account.');
        $this->assertTrue(Hash::check('FirstTime1!', $created->password));
    }

    /**
     * Step 2 cannot be POSTed on its own.
     *
     * register() never checked that step 1 had happened, so the whole verify-then-set
     * sequence could be skipped with a single direct request.
     */
    public function test_the_set_password_step_cannot_be_posted_without_completing_step_one(): void
    {
        Employee::factory()->create(['company_email' => 'direct@claritas.test', 'active_until' => null]);

        $this->post('/register', [
            'work_email' => 'direct@claritas.test',
            'password' => 'Sneaky1!',
            'password_confirmation' => 'Sneaky1!',
        ])->assertRedirect(route('register'));

        $this->assertNull(
            User::where('work_email', 'direct@claritas.test')->first(),
            'A direct POST to /register must not create an account.'
        );
    }

    /**
     * An address cleared for one account cannot be spent on another.
     */
    public function test_a_cleared_address_cannot_be_swapped_for_a_different_one(): void
    {
        Employee::factory()->create(['company_email' => 'cleared@claritas.test', 'active_until' => null]);
        Employee::factory()->create(['company_email' => 'other@claritas.test', 'active_until' => null]);

        $this->post('/register/check-email', ['work_email' => 'cleared@claritas.test']);

        $this->post('/register', [
            'work_email' => 'other@claritas.test',
            'password' => 'Swapped1!',
            'password_confirmation' => 'Swapped1!',
        ])->assertRedirect(route('register'));

        $this->assertNull(User::where('work_email', 'other@claritas.test')->first());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Finding 5 — the registration flow as an employee-directory oracle
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * "Not staff" and "already registered" must be indistinguishable.
     *
     * They used to carry different messages, which turned this unauthenticated endpoint
     * into a directory lookup — and, worse, reported which accounts were in a state the
     * rehire branch would accept. login() and sendResetLink() already take pains to avoid
     * exactly this; the registration flow quietly undid it.
     */
    public function test_the_email_check_cannot_tell_a_stranger_from_a_registered_colleague(): void
    {
        $this->activeStaff('registered@claritas.test');

        // Read each message IMMEDIATELY after its own request. TestResponse::getSession()
        // hands back the LIVE session rather than a snapshot, so capturing both responses
        // first and comparing afterwards reads request 2's errors twice — an assertion
        // that can never fail. This test is worthless unless the two reads are separated.
        $this->post('/register/check-email', ['work_email' => 'nobody@example.test']);
        $strangerMessage = session('errors')?->first('work_email');

        $this->flushSession();

        $this->post('/register/check-email', ['work_email' => 'registered@claritas.test']);
        $colleagueMessage = session('errors')?->first('work_email');

        $this->assertNotNull($strangerMessage, 'Pre-condition: an unknown address must be refused.');
        $this->assertNotNull($colleagueMessage, 'Pre-condition: an already-registered address must be refused.');
        $this->assertSame(
            $strangerMessage,
            $colleagueMessage,
            'The two outcomes must give the same answer, or the endpoint enumerates staff.'
        );
    }

    /** The enumeration endpoint is metered. */
    public function test_the_email_check_is_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post('/register/check-email', ['work_email' => "probe{$i}@example.test"]);
        }

        $this->post('/register/check-email', ['work_email' => 'probe-final@example.test'])
            ->assertStatus(429);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Finding 3 — no authorization on the HR attendance endpoints
    // ─────────────────────────────────────────────────────────────────────────

    /** @return array{0: User, 1: Employee} */
    private function plainEmployee(): array
    {
        $user = User::factory()->create(['role' => 'employee']);
        $employee = Employee::factory()->create(['user_id' => $user->id, 'active_until' => null]);

        return [$user, $employee];
    }

    /**
     * Every /hr/attendance/* read is closed to a rank-and-file account.
     *
     * These returned 200 to any authenticated user — the whole company's attendance
     * records and per-employee absence/lateness aggregates, to everyone.
     */
    public function test_a_plain_employee_cannot_read_the_hr_attendance_pages(): void
    {
        [$user] = $this->plainEmployee();

        foreach ([
            '/hr/attendance',
            '/hr/attendance/report',
            '/hr/attendance/overtime',
            '/hr/attendance/schedules',
        ] as $url) {
            $this->actingAs($user)->get($url)->assertForbidden();
        }
    }

    /**
     * The one that costs money: self-approved overtime.
     *
     * approveOvertime() had no gate at all, and PayrollController reads the approved hours
     * straight into the payslip — so any employee could raise overtime and sign it off,
     * with their own name recorded as the approver.
     */
    public function test_an_employee_cannot_approve_their_own_overtime(): void
    {
        [$user, $employee] = $this->plainEmployee();

        $overtime = OvertimeRequest::create([
            'employee_id' => $employee->id,
            'date' => now()->subDay()->toDateString(),
            'start_time' => '18:00',
            'end_time' => '22:00',
            'hours' => 4,
            'multiplier' => 1.5,
            'status' => 'pending',
        ]);

        $this->actingAs($user)
            ->post("/hr/attendance/overtime/{$overtime->id}/approve")
            ->assertForbidden();

        $this->assertSame('pending', $overtime->fresh()->status, 'The request must still be pending.');
    }

    /**
     * Separation of duties holds at the top of the tree too.
     *
     * An HR manager clears the role gate, so without a second check they could sign off
     * their own overtime — self-payment with their own name in approved_by.
     */
    public function test_even_an_hr_manager_cannot_approve_their_own_overtime(): void
    {
        $manager = User::factory()->hrManager()->create();
        $employee = Employee::factory()->create(['user_id' => $manager->id, 'active_until' => null]);

        $overtime = OvertimeRequest::create([
            'employee_id' => $employee->id,
            'date' => now()->subDay()->toDateString(),
            'start_time' => '18:00',
            'end_time' => '20:00',
            'hours' => 2,
            'multiplier' => 1.5,
            'status' => 'pending',
        ]);

        $this->actingAs($manager)
            ->post("/hr/attendance/overtime/{$overtime->id}/approve")
            ->assertForbidden();

        $this->assertSame('pending', $overtime->fresh()->status);
    }

    /**
     * Deciding overtime is narrower than reading the queue.
     *
     * An HR intern legitimately works the records desk but must not settle a payroll
     * figure. This is why authorizeOvertimeDecision() is separate from authorizeHr().
     */
    public function test_an_hr_intern_may_read_the_overtime_queue_but_not_decide_it(): void
    {
        $intern = User::factory()->hrIntern()->create();
        [, $someoneElse] = $this->plainEmployee();

        $overtime = OvertimeRequest::create([
            'employee_id' => $someoneElse->id,
            'date' => now()->subDay()->toDateString(),
            'start_time' => '18:00',
            'end_time' => '21:00',
            'hours' => 3,
            'multiplier' => 1.5,
            'status' => 'pending',
        ]);

        $this->actingAs($intern)->get('/hr/attendance/overtime')->assertOk();

        $this->actingAs($intern)
            ->post("/hr/attendance/overtime/{$overtime->id}/approve")
            ->assertForbidden();

        $this->assertSame('pending', $overtime->fresh()->status);
    }

    /** An HR manager still approves somebody else's overtime — the feature works. */
    public function test_an_hr_manager_can_still_approve_another_persons_overtime(): void
    {
        $manager = User::factory()->hrManager()->create();
        Employee::factory()->create(['user_id' => $manager->id, 'active_until' => null]);
        [, $staff] = $this->plainEmployee();

        $overtime = OvertimeRequest::create([
            'employee_id' => $staff->id,
            'date' => now()->subDay()->toDateString(),
            'start_time' => '18:00',
            'end_time' => '21:00',
            'hours' => 3,
            'multiplier' => 1.5,
            'status' => 'pending',
        ]);

        AttendanceRecord::create([
            'employee_id' => $staff->id,
            'date' => now()->subDay()->toDateString(),
            'status' => 'present',
        ]);

        $this->actingAs($manager)->post("/hr/attendance/overtime/{$overtime->id}/approve");

        $this->assertSame('approved', $overtime->fresh()->status);
    }

    /** HR still reaches its own pages — the gate admits the intended audience. */
    public function test_hr_and_admin_still_reach_the_attendance_pages(): void
    {
        foreach ([
            User::factory()->hrManager()->create(),
            User::factory()->hrExecutive()->create(),
            User::factory()->superadmin()->create(),
            User::factory()->systemAdmin()->create(),
        ] as $user) {
            $this->actingAs($user)->get('/hr/attendance')->assertOk();
            $this->actingAs($user)->get('/hr/attendance/report')->assertOk();
        }
    }

    /** Company-wide work schedules are not writable by rank-and-file accounts. */
    public function test_a_plain_employee_cannot_create_a_work_schedule(): void
    {
        [$user] = $this->plainEmployee();

        $this->actingAs($user)->post('/hr/attendance/schedules', [
            'name' => 'Injected',
            'start_time' => '09:00',
            'end_time' => '18:00',
            'work_hours_per_day' => 8,
            'working_days' => [1, 2, 3, 4, 5],
        ])->assertForbidden();

        $this->assertNull(WorkSchedule::where('name', 'Injected')->first());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Finding 2 — the TOTP seed must not leave this process
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * The 2FA enrolment page renders its QR locally.
     *
     * It used to build the image with api.qrserver.com, which put the otpauth URI — the
     * raw TOTP seed and the account's work email — in a query string to a third party.
     * The seed is permanent, so anyone who read that request could mint valid codes
     * forever. Asserted on the HOST rather than the full URL: any remote QR service is
     * the same mistake.
     */
    public function test_the_two_factor_qr_never_sends_the_secret_to_a_third_party(): void
    {
        $user = User::factory()->create(['role' => 'employee']);

        $response = $this->actingAs($user)->get('/two-factor/setup');
        $response->assertOk();

        $html = $response->getContent();

        $this->assertStringNotContainsString('qrserver.com', $html);
        $this->assertStringNotContainsString('otpauth://', $html, 'The otpauth URI must not appear in an attribute a browser would fetch.');
        $this->assertStringContainsString('<svg', $html, 'The QR must be rendered inline, server-side.');
    }

    /** The CSP no longer allow-lists a QR host, so a reintroduced remote image is blocked. */
    public function test_the_content_security_policy_allows_no_remote_image_host(): void
    {
        $user = User::factory()->create(['role' => 'employee']);

        $csp = $this->actingAs($user)->get('/two-factor/setup')->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("img-src 'self' data: blob:", $csp);
        $this->assertStringNotContainsString('qrserver.com', (string) $csp);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Finding 4 — CSV formula injection in the exports
    // ─────────────────────────────────────────────────────────────────────────

    /** The payload a spreadsheet would execute, in a field a user controls. */
    private const FORMULA = '=cmd|\'/C calc\'!A0';

    /**
     * The onboarding export is the worst case: its text comes from the PUBLIC invite form.
     *
     * An unauthenticated invitee typed a name, HR exported the list, and the cell ran as a
     * formula on the HR workstation.
     */
    public function test_the_onboarding_export_neutralises_a_formula_from_the_public_invite_form(): void
    {
        $hr = User::factory()->hrManager()->create();

        $onboarding = \App\Models\Onboarding::factory()->create();
        PersonalDetail::factory()->create([
            'onboarding_id' => $onboarding->id,
            'full_name' => self::FORMULA,
        ]);
        WorkDetail::factory()->create(['onboarding_id' => $onboarding->id]);

        $csv = $this->actingAs($hr)->get('/onboarding/export/csv')->streamedContent();

        $this->assertStringContainsString("'".self::FORMULA, $csv, 'The cell must be prefixed so the spreadsheet treats it as text.');
        $this->assertDoesNotMatchRegularExpression('/(^|,|")'.preg_quote(self::FORMULA, '/').'/m', $csv);
    }

    /** The employee export carries self-service text and needs the same treatment. */
    public function test_the_employee_export_neutralises_a_formula_in_a_self_service_field(): void
    {
        $hr = User::factory()->hrManager()->create();

        Employee::factory()->create([
            'full_name' => self::FORMULA,
            'residential_address' => self::FORMULA,
            'active_until' => null,
        ]);

        $csv = $this->actingAs($hr)->get('/hr/employees/export')->streamedContent();

        $this->assertStringContainsString("'".self::FORMULA, $csv);
        $this->assertDoesNotMatchRegularExpression('/(^|,|")'.preg_quote(self::FORMULA, '/').'/m', $csv);
    }

    /** Ordinary values are untouched — the fix must not corrupt a normal export. */
    public function test_the_exports_leave_ordinary_values_alone(): void
    {
        $hr = User::factory()->hrManager()->create();

        Employee::factory()->create([
            'full_name' => 'Ahmad bin Abdullah',
            'department' => 'Marketing',
            'active_until' => null,
        ]);

        $csv = $this->actingAs($hr)->get('/hr/employees/export')->streamedContent();

        $this->assertStringContainsString('Ahmad bin Abdullah', $csv);
        $this->assertStringNotContainsString("'Ahmad bin Abdullah", $csv);
        $this->assertStringNotContainsString("'Marketing", $csv);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Finding 5b — the orphaned manager-email lookup
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Resolving a display name to a work email is not a lookup for everyone.
     *
     * Ungated, this let any authenticated account walk the staff directory — including
     * superadmins' addresses — from an endpoint no screen even calls.
     */
    public function test_the_manager_email_lookup_is_closed_to_ordinary_accounts(): void
    {
        [$user] = $this->plainEmployee();
        $target = User::factory()->superadmin()->create(['name' => 'Target Person']);

        $response = $this->actingAs($user)->get('/onboarding/manager-email?name=Target+Person');

        $response->assertForbidden();
        $this->assertStringNotContainsString($target->work_email, $response->getContent());
    }

    /** HR, who the endpoint exists for, still gets its answer. */
    public function test_the_manager_email_lookup_still_answers_for_hr(): void
    {
        $hr = User::factory()->hrManager()->create();
        $manager = User::factory()->create(['name' => 'Aisha Rahman']);

        $this->actingAs($hr)
            ->get('/onboarding/manager-email?name=Aisha+Rahman')
            ->assertOk()
            ->assertJson(['email' => $manager->work_email]);
    }
}
