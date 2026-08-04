<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression cover for the "Urgent ticket nobody can see and nobody can take"
 * bug, found 2026-08-04 on TIC-2026-0118.
 *
 * Root cause: two functions disagreed about who counts as a department member.
 *  - User::employee() is hasOne(Employee)->whereNull('active_until'), so every
 *    whereHas('employee') path — notably picPoolForDeptAndCompany() — silently
 *    drops departed staff.
 *  - defaultServedCompanyIdsForDepartment() queried Employee DIRECTLY and so
 *    counted leavers.
 *
 * Consequence: a company whose entire team for a department had left still
 * counted as a valid service provider. A ticket routed there therefore looked
 * correctly-routed to scopeVisibleTo() (so the orphan safety net stayed quiet)
 * while the PIC pool held nobody but the sysadmins — invisible to every
 * manager AND assignable to no one.
 *
 * The companion analytics fix is covered by TicketManagerAnalyticsScopeTest.
 */
class TicketDepartedStaffProviderTest extends TestCase
{
    use RefreshDatabase;

    private Company $alpha;   // keeps a live Consulting team

    private Company $beta;    // its Consulting team leaves

    protected function setUp(): void
    {
        parent::setUp();

        $this->alpha = Company::create(['name' => 'Alpha Sdn Bhd']);
        $this->beta = Company::create(['name' => 'Beta Sdn Bhd']);
    }

    /** Consulting is work-role-gated: membership comes from employees.department. */
    private function consultingManagerAt(Company $company): User
    {
        $user = User::factory()->create(['role' => 'employee']);
        Employee::factory()->withUser($user)->create([
            'company' => $company->name,
            'department' => 'Consulting',
            'work_role' => 'manager',
        ]);

        return $user;
    }

    private function departedConsultantAt(Company $company): User
    {
        $user = User::factory()->create(['role' => 'employee']);
        Employee::factory()->withUser($user)->deactivated()->create([
            'company' => $company->name,
            'department' => 'Consulting',
            'work_role' => 'others',
        ]);

        return $user;
    }

    private function employeeAt(Company $company): User
    {
        $user = User::factory()->create(['role' => 'employee']);
        Employee::factory()->withUser($user)->create(['company' => $company->name]);

        return $user;
    }

    private function consultingTicket(Company $raiserCompany, Company $serviceCompany, User $raiser): Ticket
    {
        return Ticket::create([
            'user_id' => $raiser->id,
            'company_id' => $raiserCompany->id,
            'service_company_id' => $serviceCompany->id,
            'department' => 'Consulting',
            'priority' => 'Urgent',
            'subject' => 'Other — Issue Informed by Customer',
            'description' => 'Customer reported a problem.',
            'status' => 'Pending',
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Fix 1 — departed staff no longer make a company a service provider
    // ─────────────────────────────────────────────────────────────────────

    public function test_a_company_whose_only_team_member_left_is_not_a_service_provider(): void
    {
        $this->consultingManagerAt($this->alpha);
        $this->departedConsultantAt($this->beta);

        $providers = Ticket::sourceCompanyIdsForDepartment('Consulting');

        $this->assertContains($this->alpha->id, $providers,
            'Alpha has a live Consulting team and must remain a provider.');
        $this->assertNotContains($this->beta->id, $providers,
            'Beta\'s only consultant has left — it must not still be advertised as a Consulting provider.');
    }

    public function test_a_live_team_member_still_makes_the_company_a_provider(): void
    {
        // Guards against over-correcting the filter into "nobody qualifies".
        $this->consultingManagerAt($this->beta);
        $this->departedConsultantAt($this->beta);

        $this->assertContains(
            $this->beta->id,
            Ticket::sourceCompanyIdsForDepartment('Consulting'),
            'One departed colleague must not retire a company that still has a live team.'
        );
    }

    public function test_departed_staff_do_not_make_an_app_role_gated_company_a_provider(): void
    {
        // Group IT resolves membership from users.role, via a join on employees —
        // the second branch of defaultServedCompanyIdsForDepartment().
        $departedItManager = User::factory()->itManager()->create();
        Employee::factory()->withUser($departedItManager)->deactivated()->create([
            'company' => $this->beta->name,
            'work_role' => 'manager',
        ]);

        $this->assertNotContains(
            $this->beta->id,
            Ticket::sourceCompanyIdsForDepartment('Group IT'),
            'A departed it_manager must not keep their company listed as a Group IT provider.'
        );
    }

    /**
     * The invariant that actually broke. A company may only be offered as a
     * service provider if somebody there can be made PIC — otherwise a ticket
     * routed to it can never be worked.
     */
    public function test_a_company_is_never_advertised_as_a_provider_with_an_empty_pic_pool(): void
    {
        $this->consultingManagerAt($this->alpha);
        $this->departedConsultantAt($this->beta);

        foreach (Ticket::sourceCompanyIdsForDepartment('Consulting') as $companyId) {
            $realPool = Ticket::picPoolForDeptAndCompany('Consulting', $companyId, true)
                ->whereNotIn('users.role', ['superadmin', 'system_admin'])
                ->count();

            $this->assertGreaterThan(0, $realPool,
                "Company #{$companyId} is advertised as a Consulting provider but has no assignable PIC.");
        }
    }

    public function test_a_ticket_routed_to_a_departed_team_is_rescued_by_the_orphan_safety_net(): void
    {
        // The exact TIC-2026-0118 shape: raised at Alpha, routed to Beta, whose
        // Consulting team has since left. Before the fix this was visible to
        // superadmins only.
        $alphaManager = $this->consultingManagerAt($this->alpha);
        $this->departedConsultantAt($this->beta);
        $raiser = $this->employeeAt($this->alpha);

        $ticket = $this->consultingTicket($this->alpha, $this->beta, $raiser);

        $this->assertTrue(
            Ticket::visibleTo($alphaManager)->pluck('id')->contains($ticket->id),
            'A ticket whose service company no longer has a team must surface to the live department manager.'
        );
    }
}
