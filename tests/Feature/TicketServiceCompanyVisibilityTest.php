<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression cover for the "superadmin-resolved IT ticket only shows in the
 * superadmin's archive" bug.
 *
 * Root cause: Ticket::scopeVisibleTo() matches a manager's own company against
 * tickets.service_company_id. A ticket created or re-routed by a superadmin
 * (who has no employee record / no company) could land on a service company
 * that doesn't actually run the ticket's department — stranding it: visible to
 * the superadmin (who bypasses the scope) but to no department manager.
 *
 * Three fixes are asserted here:
 *  1. scopeVisibleTo() orphan safety net — a ticket whose service_company_id
 *     is not a valid provider for its department is surfaced to every manager
 *     of that department whose own company IS a valid provider.
 *  2. updateAdmin() re-route recomputes service_company_id for the new dept.
 *  3. The data-fix migration repairs already-stranded tickets.
 */
class TicketServiceCompanyVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private Company $alpha;   // an IT-providing company

    private Company $beta;    // a second IT-providing company

    protected function setUp(): void
    {
        parent::setUp();

        $this->alpha = Company::create(['name' => 'Alpha Sdn Bhd']);
        $this->beta = Company::create(['name' => 'Beta Sdn Bhd']);
    }

    /**
     * Create an it_manager User linked to an Employee at the given company.
     * Group IT is app-role-gated, so an it_manager whose employee.company is
     * a Group-IT-providing company manages Group IT for that company.
     */
    private function itManagerAt(Company $company): User
    {
        $user = User::factory()->itManager()->create();
        Employee::factory()->withUser($user)->create([
            'company' => $company->name,
            'work_role' => 'manager',
        ]);

        return $user;
    }

    /** Create a plain employee (raiser) at the given company. */
    private function employeeAt(Company $company): User
    {
        $user = User::factory()->create(['role' => 'employee']);
        Employee::factory()->withUser($user)->create(['company' => $company->name]);

        return $user;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Fix 1 — scopeVisibleTo() orphan safety net
    // ─────────────────────────────────────────────────────────────────────

    public function test_correctly_routed_ticket_is_visible_to_its_service_company_manager(): void
    {
        // Baseline: the strict match still works for a clean ticket.
        $alphaManager = $this->itManagerAt($this->alpha);
        $raiser = $this->employeeAt($this->alpha);

        $ticket = Ticket::create([
            'user_id' => $raiser->id,
            'company_id' => $this->alpha->id,
            'service_company_id' => $this->alpha->id,   // valid: Alpha runs Group IT
            'department' => 'Group IT',
            'priority' => 'Medium',
            'subject' => 'Email Problem',
            'description' => 'Cannot send mail.',
            'status' => 'Open',
        ]);

        $visibleIds = Ticket::visibleTo($alphaManager)->pluck('id');
        $this->assertTrue($visibleIds->contains($ticket->id),
            'Alpha IT manager should see a ticket whose service company is Alpha.');
    }

    public function test_stranded_ticket_is_visible_to_department_managers_via_orphan_safety_net(): void
    {
        // The bug scenario: a ticket whose service_company_id points at a
        // company that does NOT run Group IT (here: a third, non-IT company).
        // Before the fix this ticket was invisible to every IT manager.
        $alphaManager = $this->itManagerAt($this->alpha);
        $betaManager = $this->itManagerAt($this->beta);
        $raiser = $this->employeeAt($this->alpha);

        // A company with no Group IT team at all.
        $outsider = Company::create(['name' => 'Outsider Sdn Bhd']);

        $stranded = Ticket::create([
            'user_id' => $raiser->id,
            'company_id' => $this->alpha->id,
            'service_company_id' => $outsider->id,   // NOT a Group IT provider
            'department' => 'Group IT',
            'priority' => 'High',
            'subject' => 'Account Lockout',
            'description' => 'Locked out.',
            'status' => 'Open',
        ]);

        // Both IT managers (Alpha and Beta both run Group IT) should now see it.
        $this->assertTrue(
            Ticket::visibleTo($alphaManager)->pluck('id')->contains($stranded->id),
            'Alpha IT manager should see a stranded Group IT ticket via the orphan safety net.'
        );
        $this->assertTrue(
            Ticket::visibleTo($betaManager)->pluck('id')->contains($stranded->id),
            'Beta IT manager should see a stranded Group IT ticket via the orphan safety net.'
        );
    }

    public function test_stranded_resolved_ticket_still_visible_to_department_managers(): void
    {
        // The exact user-reported symptom: a superadmin RESOLVED the ticket;
        // it must still appear (in the Archived tab) for the dept managers,
        // not only in the superadmin's archive.
        $alphaManager = $this->itManagerAt($this->alpha);
        $raiser = $this->employeeAt($this->alpha);
        $outsider = Company::create(['name' => 'Outsider Sdn Bhd']);

        $resolved = Ticket::create([
            'user_id' => $raiser->id,
            'company_id' => $this->alpha->id,
            'service_company_id' => $outsider->id,   // stranded
            'department' => 'Group IT',
            'priority' => 'Low',
            'subject' => 'Printer Issue',
            'description' => 'Printer jammed.',
            'status' => 'Resolved',
            'resolved_at' => now(),
        ]);

        $this->assertTrue(
            Ticket::visibleTo($alphaManager)->pluck('id')->contains($resolved->id),
            'A superadmin-resolved stranded ticket must still be visible to the department manager.'
        );
    }

    public function test_orphan_safety_net_does_not_leak_other_departments(): void
    {
        // The safety net is per-department: an IT manager must NOT see a
        // stranded ticket from a department they do not manage.
        $alphaItManager = $this->itManagerAt($this->alpha);
        $raiser = $this->employeeAt($this->alpha);
        $outsider = Company::create(['name' => 'Outsider Sdn Bhd']);

        $financeTicket = Ticket::create([
            'user_id' => $raiser->id,
            'company_id' => $this->alpha->id,
            'service_company_id' => $outsider->id,   // stranded, but Finance
            'department' => 'Finance',
            'priority' => 'Medium',
            'subject' => 'Invoice Query',
            'description' => 'Wrong invoice.',
            'status' => 'Open',
        ]);

        $this->assertFalse(
            Ticket::visibleTo($alphaItManager)->pluck('id')->contains($financeTicket->id),
            'An IT manager must not see a stranded Finance ticket — the safety net is dept-scoped.'
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // Fix 2 — updateAdmin() recomputes service_company_id on re-route
    // ─────────────────────────────────────────────────────────────────────

    public function test_superadmin_reroute_updates_service_company_to_new_department(): void
    {
        // A ticket misfiled as "Tech" by an Alpha employee, re-routed to
        // "Group IT" by a superadmin. After the re-route the service company
        // must be Alpha (same company, new dept) — so Alpha's IT manager
        // sees it. Before the fix it kept the Tech service company.
        // withTwoFactor() satisfies the force-2FA-enrollment middleware that
        // otherwise redirects every authenticated request to /two-factor/setup.
        $superadmin = User::factory()->superadmin()->withTwoFactor()->create(); // no employee
        $alphaManager = $this->itManagerAt($this->alpha);
        $raiser = $this->employeeAt($this->alpha);

        // Give Alpha a Tech team too, so the original ticket is validly routed.
        $alphaTechMgr = User::factory()->create(['role' => 'employee']);
        Employee::factory()->withUser($alphaTechMgr)->create([
            'company' => $this->alpha->name,
            'department' => 'Tech',
            'work_role' => 'manager',
        ]);

        $ticket = Ticket::create([
            'user_id' => $raiser->id,
            'company_id' => $this->alpha->id,
            'service_company_id' => $this->alpha->id,
            'department' => 'Tech',
            'priority' => 'Medium',
            'subject' => 'Bug Report',
            'description' => 'Something broke.',
            'status' => 'Open',
        ]);

        $response = $this->actingAs($superadmin)->put(
            route('tickets.update-admin', $ticket),
            ['department' => 'Group IT']
        );
        $response->assertSessionHasNoErrors();
        $response->assertRedirect();

        $ticket->refresh();
        $this->assertSame('Group IT', $ticket->department);
        $this->assertSame($this->alpha->id, $ticket->service_company_id,
            'Re-route must move service_company_id to a valid provider of the new department.');
        $this->assertTrue(
            Ticket::isValidServiceCompanyForDepartment($ticket->service_company_id, 'Group IT'),
            'After re-route the service company must be a valid Group IT provider.'
        );
        $this->assertTrue(
            Ticket::visibleTo($alphaManager)->pluck('id')->contains($ticket->id),
            'After the superadmin re-route the Group IT manager should see the ticket.'
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // Fix 3 — data-fix migration repairs already-stranded tickets
    // ─────────────────────────────────────────────────────────────────────

    public function test_data_fix_migration_repairs_stranded_tickets(): void
    {
        $this->itManagerAt($this->alpha);   // makes Alpha a Group IT provider
        $raiser = $this->employeeAt($this->alpha);
        $outsider = Company::create(['name' => 'Outsider Sdn Bhd']);

        $stranded = Ticket::create([
            'user_id' => $raiser->id,
            'company_id' => $this->alpha->id,
            'service_company_id' => $outsider->id,   // wrong provider
            'department' => 'Group IT',
            'priority' => 'Medium',
            'subject' => 'Email Problem',
            'description' => 'Mail down.',
            'status' => 'Resolved',
            'resolved_at' => now(),
        ]);

        // Run only the data-fix migration's repair logic.
        (require database_path('migrations/2026_05_15_000001_fix_stranded_ticket_service_company.php'))
            ->up();

        $stranded->refresh();
        $this->assertSame($this->alpha->id, $stranded->service_company_id,
            'The migration should re-resolve the stranded ticket to Alpha (the raiser company that runs Group IT).');
    }

    public function test_data_fix_migration_leaves_valid_tickets_untouched(): void
    {
        $this->itManagerAt($this->alpha);
        $raiser = $this->employeeAt($this->alpha);

        $valid = Ticket::create([
            'user_id' => $raiser->id,
            'company_id' => $this->alpha->id,
            'service_company_id' => $this->alpha->id,   // already correct
            'department' => 'Group IT',
            'priority' => 'Low',
            'subject' => 'Password Reset',
            'description' => 'Forgot password.',
            'status' => 'Open',
        ]);

        (require database_path('migrations/2026_05_15_000001_fix_stranded_ticket_service_company.php'))
            ->up();

        $valid->refresh();
        $this->assertSame($this->alpha->id, $valid->service_company_id,
            'A correctly-routed ticket must be left untouched by the data-fix migration.');
    }
}
