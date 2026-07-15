<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeCompanyHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The per-employee company timeline: recordCompanyStintChange() reconciles the timeline
 * against the employee's current company, keeping past stints when they return to a prior one.
 */
class EmployeeCompanyTimelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_changes_open_and_close_stints_and_keep_history(): void
    {
        $emp = Employee::factory()->create([
            'company' => 'Claritas',
            'office_location' => 'Claritas HQ',
        ]);

        // First reconcile seeds the opening stint from the start date (mirrors the backfill).
        $emp->recordCompanyStintChange();
        $this->assertSame(1, $emp->companyHistories()->count());

        // Move to Enlinea.
        $emp->update(['company' => 'Enlinea', 'office_location' => 'Enlinea Tower']);
        $this->assertTrue($emp->recordCompanyStintChange());

        // Move back to Claritas — a fresh stint, the original Claritas stint is preserved.
        $emp->update(['company' => 'Claritas', 'office_location' => 'Claritas HQ']);
        $this->assertTrue($emp->recordCompanyStintChange());

        $stints = $emp->companyHistories()->orderBy('id')->get();
        $this->assertCount(3, $stints);
        $this->assertSame(['Claritas', 'Enlinea', 'Claritas'], $stints->pluck('company')->all());

        // Exactly one open stint, and it's the company they're currently at.
        $open = $stints->whereNull('ended_on');
        $this->assertCount(1, $open);
        $this->assertSame('Claritas', $open->first()->company);

        // The earlier Claritas + the Enlinea stints are closed.
        $this->assertNotNull($stints[0]->ended_on);
        $this->assertNotNull($stints[1]->ended_on);
    }

    public function test_same_company_office_move_updates_stint_in_place(): void
    {
        $emp = Employee::factory()->create(['company' => 'Claritas', 'office_location' => 'Tower A']);
        $emp->recordCompanyStintChange();

        // Same company, new office — no new stint, current stint's location updates.
        $emp->update(['office_location' => 'Tower B']);
        $this->assertFalse($emp->recordCompanyStintChange());

        $this->assertSame(1, $emp->companyHistories()->count());
        $this->assertSame('Tower B', $emp->companyHistories()->whereNull('ended_on')->first()->office_location);
    }

    public function test_no_change_is_a_noop(): void
    {
        $emp = Employee::factory()->create(['company' => 'Claritas', 'office_location' => 'HQ']);
        $emp->recordCompanyStintChange();

        $this->assertFalse($emp->recordCompanyStintChange());
        $this->assertSame(1, EmployeeCompanyHistory::where('employee_id', $emp->id)->count());
    }
}
