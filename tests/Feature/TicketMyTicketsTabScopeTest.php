<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression cover for the "Assigned to Me tab shows Resolved/Closed tickets"
 * bug on the My Tickets page (/tickets, TicketController@index).
 *
 * Root cause: the 'assigned' branch filtered only on assigned_to and pulled
 * EVERY status, relying on FIELD() ordering to sink terminal tickets to the
 * bottom — so Resolved/Closed tickets stayed on the active tab instead of
 * moving to Archived.
 *
 * Fixes asserted here:
 *  1. 'assigned' tab = ACTIVE statuses only (Open/In Progress/Pending).
 *  2. 'archived' tab = terminal tickets the user RAISED *or* is PIC of,
 *     so a resolved assigned ticket stays visible after it leaves 'assigned'.
 *  3. The three tab badge counts mirror exactly what their tab renders.
 */
class TicketMyTicketsTabScopeTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $pic;       // the IT manager viewing /tickets

    private User $raiser;    // a plain employee who raised tickets

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['name' => 'Alpha Sdn Bhd']);

        // withTwoFactor() satisfies the force-2FA-enrollment middleware that
        // otherwise redirects every authenticated request to /two-factor/setup.
        $this->pic = User::factory()->itManager()->withTwoFactor()->create();
        Employee::factory()->withUser($this->pic)->create([
            'company' => $this->company->name,
            'work_role' => 'manager',
        ]);

        $this->raiser = User::factory()->create(['role' => 'employee']);
        Employee::factory()->withUser($this->raiser)->create([
            'company' => $this->company->name,
        ]);
    }

    /** Create a ticket with the given status, optionally PIC'd to $this->pic. */
    private function ticket(string $status, bool $assignedToPic, ?int $raiserId = null): Ticket
    {
        return Ticket::create([
            'user_id' => $raiserId ?? $this->raiser->id,
            'company_id' => $this->company->id,
            'service_company_id' => $this->company->id,
            'department' => 'Group IT',
            'priority' => 'Medium',
            'subject' => 'Email Problem',
            'description' => 'Mail issue.',
            'status' => $status,
            'assigned_to' => $assignedToPic ? $this->pic->id : null,
            'assigned_at' => $assignedToPic ? now() : null,
            'resolved_at' => in_array($status, Ticket::ARCHIVED_STATUSES, true) ? now() : null,
        ]);
    }

    /** Pull the rendered ticket collection for a given tab scope. */
    private function ticketsOnTab(string $scope)
    {
        $response = $this->actingAs($this->pic)
            ->get(route('tickets.index', ['scope' => $scope]));
        $response->assertOk();

        return $response->viewData('tickets');
    }

    public function test_assigned_tab_shows_only_active_assigned_tickets(): void
    {
        $open = $this->ticket('Open', assignedToPic: true);
        $inProgress = $this->ticket('In Progress', assignedToPic: true);
        $pending = $this->ticket('Pending', assignedToPic: true);
        $resolved = $this->ticket('Resolved', assignedToPic: true);
        $closed = $this->ticket('Closed', assignedToPic: true);

        $ids = $this->ticketsOnTab('assigned')->pluck('id');

        $this->assertTrue($ids->contains($open->id), 'Open assigned ticket should appear.');
        $this->assertTrue($ids->contains($inProgress->id), 'In Progress assigned ticket should appear.');
        $this->assertTrue($ids->contains($pending->id), 'Pending assigned ticket should appear.');

        $this->assertFalse($ids->contains($resolved->id),
            'Resolved assigned ticket must NOT appear on the Assigned to Me tab.');
        $this->assertFalse($ids->contains($closed->id),
            'Closed assigned ticket must NOT appear on the Assigned to Me tab.');
    }

    public function test_archived_tab_includes_resolved_assigned_tickets_user_did_not_raise(): void
    {
        // PIC'd to the viewer, raised by someone else, terminal status.
        $resolvedAssigned = $this->ticket('Resolved', assignedToPic: true);
        $closedAssigned = $this->ticket('Closed', assignedToPic: true);
        // Terminal ticket the viewer raised themselves.
        $resolvedRaised = $this->ticket('Resolved', assignedToPic: false, raiserId: $this->pic->id);
        // Active assigned ticket — belongs on the Assigned tab, not Archived.
        $activeAssigned = $this->ticket('Open', assignedToPic: true);

        $ids = $this->ticketsOnTab('archived')->pluck('id');

        $this->assertTrue($ids->contains($resolvedAssigned->id),
            'A resolved ticket the viewer is PIC of must appear on Archived.');
        $this->assertTrue($ids->contains($closedAssigned->id),
            'A closed ticket the viewer is PIC of must appear on Archived.');
        $this->assertTrue($ids->contains($resolvedRaised->id),
            'A resolved ticket the viewer raised must still appear on Archived.');
        $this->assertFalse($ids->contains($activeAssigned->id),
            'An active assigned ticket must NOT appear on Archived.');
    }

    public function test_tab_badge_counts_match_rendered_tickets(): void
    {
        // Raised by viewer: 1 active + 1 archived.
        $this->ticket('Open', assignedToPic: false, raiserId: $this->pic->id);
        $this->ticket('Closed', assignedToPic: false, raiserId: $this->pic->id);
        // PIC'd to viewer, raised by someone else: 2 active + 1 archived.
        $this->ticket('In Progress', assignedToPic: true);
        $this->ticket('Pending', assignedToPic: true);
        $this->ticket('Resolved', assignedToPic: true);

        $response = $this->actingAs($this->pic)->get(route('tickets.index'));
        $counts = $response->viewData('counts');

        $this->assertSame(1, $counts['active'],
            'Active badge counts only ACTIVE tickets the viewer raised.');
        $this->assertSame(2, $counts['assigned'],
            'Assigned badge counts only ACTIVE tickets the viewer is PIC of.');
        // Archived = raised-and-terminal (1) + assigned-and-terminal (1) = 2.
        $this->assertSame(2, $counts['archived'],
            'Archived badge counts terminal tickets the viewer raised OR is PIC of.');
    }
}
