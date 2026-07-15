<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\ExpenseClaim;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Employee-linked records keep the company they were created under. Claims snapshot the company
 * at submission (via the `company` column); moving the employee later must not relabel them, and
 * a correction filed after a move takes the new company.
 */
class ClaimCompanySnapshotTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_as_of_resolves_from_the_timeline(): void
    {
        $emp = Employee::factory()->create(['company' => 'Claritas', 'start_date' => '2023-01-01']);
        $emp->recordCompanyStintChange();                 // opens Claritas from start date
        $emp->update(['company' => 'Enlinea']);
        $emp->recordCompanyStintChange();                 // closes Claritas today, opens Enlinea

        $this->assertSame('Claritas', $emp->companyAsOf('2024-06-01'));   // during Claritas
        $this->assertSame('Enlinea', $emp->companyAsOf(now()->toDateString())); // after the move
    }

    public function test_submitted_claim_keeps_company_after_employee_moves(): void
    {
        $emp = Employee::factory()->create(['company' => 'Claritas']);

        // A draft has no snapshot yet — it resolves to the current company.
        $draft = ExpenseClaim::create([
            'employee_id' => $emp->id, 'claim_number' => 'EC-2026-06-9001', 'title' => 'Test Claim', 'year' => 2026, 'month' => 6, 'event' => 'Trip',
            'status' => 'draft', 'total_amount' => 0, 'total_gst' => 0, 'total_with_gst' => 0, 'item_count' => 0,
        ]);
        $this->assertSame('Claritas', $draft->resolvedCompany());

        // Simulate submission stamping the company (what submit() does).
        $draft->update(['status' => 'submitted', 'submitted_at' => now(), 'company' => $emp->company]);

        // The employee later moves to Enlinea.
        $emp->update(['company' => 'Enlinea']);
        $draft->refresh();

        $this->assertSame('Claritas', $draft->company);            // frozen snapshot
        $this->assertSame('Claritas', $draft->resolvedCompany());  // still Claritas, not Enlinea
    }

    public function test_correction_after_move_takes_the_new_company(): void
    {
        $emp = Employee::factory()->create(['company' => 'Enlinea']); // already moved

        // A fresh correction draft (no snapshot) shows the current company while editing…
        $correction = ExpenseClaim::create([
            'employee_id' => $emp->id, 'claim_number' => 'EC-2026-06-9002', 'title' => 'Test Correction', 'year' => 2026, 'month' => 6, 'event' => 'Trip (correction)',
            'status' => 'draft', 'total_amount' => 0, 'total_gst' => 0, 'total_with_gst' => 0, 'item_count' => 0,
        ]);
        $this->assertSame('Enlinea', $correction->resolvedCompany());

        // …and on submit it snapshots the current (new) company.
        $correction->update(['status' => 'submitted', 'submitted_at' => now(), 'company' => $emp->company]);
        $this->assertSame('Enlinea', $correction->fresh()->company);
    }
}
