<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression cover for the "card says 2, list shows 1" report (2026-08-04).
 *
 * Cards 2 and 3 on Ticket Management are deliberately cross-company — they are
 * labelled as averages, a benchmark you read. Card 1 is not: it says
 * "N Active Tickets", which every manager reads as "my inbox". It used to count
 * `department IN (managed)` with NO company restriction, so it included tickets
 * served by OTHER companies — which scopeVisibleTo() excludes from the listing.
 * A Consulting manager saw "2 Active Tickets" above a list holding 1, with no
 * way to reach the second, and it read as a permissions bug.
 *
 * Card 1 must therefore share the listing's scope: visibleTo().
 */
class TicketManagerAnalyticsScopeTest extends TestCase
{
    use RefreshDatabase;

    private Company $alpha;

    private Company $beta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alpha = Company::create(['name' => 'Alpha Sdn Bhd']);
        $this->beta = Company::create(['name' => 'Beta Sdn Bhd']);
    }

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

    private function consultingTicket(Company $serviceCompany, User $raiser): Ticket
    {
        return Ticket::create([
            'user_id' => $raiser->id,
            'company_id' => $this->alpha->id,
            'service_company_id' => $serviceCompany->id,
            'department' => 'Consulting',
            'priority' => 'Urgent',
            'subject' => 'Other — Issue Informed by Customer',
            'description' => 'Customer reported a problem.',
            'status' => 'Pending',
        ]);
    }

    public function test_active_tickets_card_matches_the_visible_listing(): void
    {
        // BOTH companies keep a LIVE Consulting team, so Beta is a legitimate
        // provider and its ticket is correctly invisible to Alpha's manager.
        // That isolates this from the departed-staff provider fix.
        $alphaManager = $this->consultingManagerAt($this->alpha);
        $this->consultingManagerAt($this->beta);

        $raiser = User::factory()->create(['role' => 'employee']);
        Employee::factory()->withUser($raiser)->create(['company' => $this->alpha->name]);

        $mine = $this->consultingTicket($this->alpha, $raiser);
        $theirs = $this->consultingTicket($this->beta, $raiser);

        $visible = Ticket::visibleTo($alphaManager)->pluck('id');
        $this->assertTrue($visible->contains($mine->id));
        $this->assertFalse($visible->contains($theirs->id),
            "Beta is a valid provider, so its ticket belongs to Beta's manager, not Alpha's.");

        $response = $this->actingAs($alphaManager)->get('/tickets/manage');
        $response->assertOk();

        $analytics = $response->viewData('analytics');
        $counts = $response->viewData('counts');

        $this->assertSame(1, $analytics['totalActive'],
            'The card must not count a ticket the manager cannot open.');
        $this->assertSame($counts['all'], $analytics['totalActive'],
            'The card and the All Tickets tab badge must always agree.');
        $this->assertSame(1, $analytics['byPriority']['Urgent']);
    }
}
