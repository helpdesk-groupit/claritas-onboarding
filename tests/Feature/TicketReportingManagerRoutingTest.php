<?php

namespace Tests\Feature;

use App\Mail\TicketCreatedMail;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Cover for the reporting-manager feature (3 pieces):
 *  1. Employee::resolveManagerId() — matches "preferred_name + full_name".
 *  2. The manager_id backfill migration.
 *  3. Ticketing: a SAME-department ticket also notifies the raiser's
 *     reporting manager (notification-only — visibility/PIC pool unchanged).
 *     A CROSS-department ticket does NOT.
 */
class TicketReportingManagerRoutingTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::create(['name' => 'Acme Sdn Bhd']);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Piece 2 — Employee::resolveManagerId() name matching
    // ─────────────────────────────────────────────────────────────────────

    public function test_resolve_manager_id_matches_exact_full_name(): void
    {
        $mgr = Employee::factory()->create([
            'full_name' => 'Koay Tze Lee',
            'preferred_name' => 'Koay',
        ]);

        $this->assertSame($mgr->id, Employee::resolveManagerId('Koay Tze Lee'));
    }

    public function test_resolve_manager_id_matches_preferred_plus_full_name(): void
    {
        // The dominant production pattern: reporting_manager stored as
        // "Petrina Goh Shze Yinn" while the record is full_name "Goh Shze
        // Yinn", preferred_name "Petrina".
        $mgr = Employee::factory()->create([
            'full_name' => 'Goh Shze Yinn',
            'preferred_name' => 'Petrina',
        ]);

        $this->assertSame($mgr->id, Employee::resolveManagerId('Petrina Goh Shze Yinn'));
    }

    public function test_resolve_manager_id_matches_substring(): void
    {
        $mgr = Employee::factory()->create([
            'full_name' => 'Racheal Divya Joseph A/P Alan Morgan Kirubakaran',
            'preferred_name' => 'Racheal',
        ]);

        $this->assertSame($mgr->id, Employee::resolveManagerId('Racheal Divya Joseph'));
    }

    public function test_resolve_manager_id_returns_null_when_ambiguous(): void
    {
        // Two active employees with the same name + preferred name — a real
        // duplicate-record situation. resolveManagerId must NOT guess.
        Employee::factory()->create(['full_name' => 'Tan Yew Aik', 'preferred_name' => 'Pinky']);
        Employee::factory()->create(['full_name' => 'Tan Yew Aik', 'preferred_name' => 'Pinky']);

        $this->assertNull(Employee::resolveManagerId('Pinky Tan Yew Aik'));
    }

    public function test_resolve_manager_id_returns_null_when_no_match(): void
    {
        Employee::factory()->create(['full_name' => 'Koay Tze Lee', 'preferred_name' => 'Koay']);

        $this->assertNull(Employee::resolveManagerId('Someone Not In The System'));
    }

    public function test_resolve_manager_id_ignores_inactive_employees(): void
    {
        Employee::factory()->deactivated()->create([
            'full_name' => 'Koay Tze Lee',
            'preferred_name' => 'Koay',
        ]);

        $this->assertNull(Employee::resolveManagerId('Koay Tze Lee'));
    }

    // ─────────────────────────────────────────────────────────────────────
    // Piece 2 — backfill migration
    // ─────────────────────────────────────────────────────────────────────

    public function test_backfill_migration_links_resolvable_reporting_managers(): void
    {
        $manager = Employee::factory()->create([
            'full_name' => 'Goh Shze Yinn',
            'preferred_name' => 'Petrina',
        ]);
        // A report whose reporting_manager is the "preferred + full" variant
        // and whose manager_id is still null.
        $report = Employee::factory()->create([
            'full_name' => 'Junior Staff',
            'reporting_manager' => 'Petrina Goh Shze Yinn',
            'manager_id' => null,
        ]);

        (require database_path('migrations/2026_05_15_000002_backfill_employee_manager_id.php'))
            ->up();

        $report->refresh();
        $this->assertSame($manager->id, $report->manager_id,
            'Backfill should resolve manager_id from the preferred+full reporting_manager string.');
        $this->assertSame('Goh Shze Yinn', $report->reporting_manager,
            'Backfill should normalise reporting_manager to the manager\'s canonical full_name.');
    }

    public function test_backfill_migration_leaves_unresolvable_rows_untouched(): void
    {
        // Ambiguous: two managers with the same name.
        Employee::factory()->create(['full_name' => 'Tan Yew Aik', 'preferred_name' => 'Pinky']);
        Employee::factory()->create(['full_name' => 'Tan Yew Aik', 'preferred_name' => 'Pinky']);

        $report = Employee::factory()->create([
            'full_name' => 'Junior Staff',
            'reporting_manager' => 'Pinky Tan Yew Aik',
            'manager_id' => null,
        ]);

        (require database_path('migrations/2026_05_15_000002_backfill_employee_manager_id.php'))
            ->up();

        $report->refresh();
        $this->assertNull($report->manager_id,
            'Ambiguous reporting_manager must be left for manual fixing — never guessed.');
    }

    public function test_backfill_migration_does_not_overwrite_existing_manager_id(): void
    {
        $realManager = Employee::factory()->create(['full_name' => 'Real Manager']);
        $other = Employee::factory()->create([
            'full_name' => 'Goh Shze Yinn',
            'preferred_name' => 'Petrina',
        ]);
        $report = Employee::factory()->create([
            'full_name' => 'Junior Staff',
            'reporting_manager' => 'Petrina Goh Shze Yinn',
            'manager_id' => $realManager->id,   // already set
        ]);

        (require database_path('migrations/2026_05_15_000002_backfill_employee_manager_id.php'))
            ->up();

        $report->refresh();
        $this->assertSame($realManager->id, $report->manager_id,
            'Backfill is idempotent — it must not touch rows that already have manager_id.');
    }

    // ─────────────────────────────────────────────────────────────────────
    // Piece 3 — same-dept ticket notifies the raiser's reporting manager
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Build a raiser employee in $dept with a reporting manager (a work-role
     * manager in the same dept). Returns [raiserUser, managerUser].
     *
     * @return array{0: User, 1: User}
     */
    private function raiserWithReportingManager(string $dept): array
    {
        // The reporting manager — a registered manager in $dept.
        $managerUser = User::factory()->create(['role' => 'employee']);
        $managerEmp = Employee::factory()->withUser($managerUser)->create([
            'full_name' => 'Dept Head',
            'company' => $this->company->name,
            'department' => $dept,
            'work_role' => 'manager',
        ]);

        // The raiser — reports to that manager.
        $raiserUser = User::factory()->create(['role' => 'employee']);
        Employee::factory()->withUser($raiserUser)->create([
            'full_name' => 'Raiser Person',
            'company' => $this->company->name,
            'department' => $dept,
            'work_role' => 'others',
            'manager_id' => $managerEmp->id,
        ]);

        return [$raiserUser, $managerUser];
    }

    public function test_same_department_ticket_notifies_reporting_manager(): void
    {
        Mail::fake();
        // Raiser is in 'Tech' and reports to the Tech head. Ticket is for Tech.
        [$raiser, $managerUser] = $this->raiserWithReportingManager('Tech');

        $ticket = Ticket::create([
            'user_id' => $raiser->id,
            'company_id' => $this->company->id,
            'service_company_id' => $this->company->id,
            'department' => 'Tech',
            'priority' => 'Medium',
            'subject' => 'Bug Report',
            'description' => 'Same-dept ticket.',
            'status' => 'Open',
        ]);
        $ticket->load('creator.employee');

        $rm = $ticket->reportingManagerForSameDeptNotification();
        $this->assertNotNull($rm, 'A same-dept ticket should resolve the raiser\'s reporting manager.');
        $this->assertSame($managerUser->id, $rm->id);
    }

    public function test_cross_department_ticket_does_not_route_to_reporting_manager(): void
    {
        // Raiser is in 'Digital' but raises a ticket for 'Marketing'.
        [$raiser] = $this->raiserWithReportingManager('Digital');

        $ticket = Ticket::create([
            'user_id' => $raiser->id,
            'company_id' => $this->company->id,
            'service_company_id' => $this->company->id,
            'department' => 'Marketing',   // NOT the raiser's department
            'priority' => 'Medium',
            'subject' => 'Campaign Request',
            'description' => 'Cross-dept ticket.',
            'status' => 'Open',
        ]);
        $ticket->load('creator.employee');

        $this->assertNull(
            $ticket->reportingManagerForSameDeptNotification(),
            'A cross-department ticket must NOT route to the raiser\'s own reporting manager.'
        );
    }

    public function test_ticket_with_no_reporting_manager_resolves_null(): void
    {
        // Raiser has no manager_id set.
        $raiser = User::factory()->create(['role' => 'employee']);
        Employee::factory()->withUser($raiser)->create([
            'company' => $this->company->name,
            'department' => 'Tech',
            'manager_id' => null,
        ]);

        $ticket = Ticket::create([
            'user_id' => $raiser->id,
            'company_id' => $this->company->id,
            'service_company_id' => $this->company->id,
            'department' => 'Tech',
            'priority' => 'Low',
            'subject' => 'Feature Request',
            'description' => 'No manager set.',
            'status' => 'Open',
        ]);
        $ticket->load('creator.employee');

        $this->assertNull($ticket->reportingManagerForSameDeptNotification());
    }

    public function test_reporting_manager_receives_new_ticket_mail_on_same_dept_raise(): void
    {
        Mail::fake();
        [$raiser, $managerUser] = $this->raiserWithReportingManager('Tech');
        // Give the manager a work_email so the mail path fires.
        $managerUser->update(['work_email' => 'depthead@acme.test']);

        $this->actingAs($raiser)->post(route('tickets.store'), [
            'company_id' => $this->company->id,
            'subject' => 'Bug Report',
            'description' => 'A genuine same-department ticket from the raiser.',
            'department' => 'Tech',
            'priority' => 'Medium',
        ]);

        Mail::assertQueued(TicketCreatedMail::class, function ($mail) use ($managerUser) {
            return $mail->hasTo($managerUser->work_email);
        });
    }
}
