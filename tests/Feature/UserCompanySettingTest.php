<?php

namespace Tests\Feature;

use App\Http\Middleware\EnforceTwoFactor;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Superadmin bulk company assignment with an effective (possibly back-dated) date, recorded on
 * the company timeline.
 */
class UserCompanySettingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(EnforceTwoFactor::class);
        Company::create(['name' => 'Nuren Sdn. Bhd.', 'address' => 'Nuren HQ, KL']);
    }

    public function test_only_superadmin_can_access(): void
    {
        $emp = Employee::factory()->create(['company' => 'Claritas', 'start_date' => '2024-01-01']);
        $hr = User::factory()->create(['role' => 'hr_manager']);
        $this->actingAs($hr)->get(route('superadmin.user-company-settings.index'))->assertForbidden();

        $super = User::factory()->create(['role' => 'superadmin']);
        Employee::factory()->create(['user_id' => $super->id, 'company' => 'Claritas']); // sidebar needs an employee
        $this->actingAs($super)->get(route('superadmin.user-company-settings.index'))->assertOk();
    }

    public function test_bulk_change_splits_timeline_at_a_back_dated_effective_date(): void
    {
        $super = User::factory()->create(['role' => 'superadmin']);
        Employee::factory()->create(['user_id' => $super->id, 'company' => 'Claritas']); // sidebar needs an employee
        $emp = Employee::factory()->create(['company' => 'Claritas', 'start_date' => '2024-01-01']);
        $emp->ensureInitialCompanyStint(); // opens Claritas from 2024-01-01

        // Move to Nuren effective a PAST date within the current stint.
        $this->actingAs($super)->post(route('superadmin.user-company-settings.bulk-assign'), [
            'employee_ids' => [$emp->id],
            'company' => 'Nuren Sdn. Bhd.',
            'effective_date' => '2026-03-01',
        ])->assertRedirect();

        $emp->refresh();
        $this->assertSame('Nuren Sdn. Bhd.', $emp->company);
        $this->assertSame('Nuren HQ, KL', $emp->office_location); // office followed the company

        $stints = $emp->companyHistories()->orderBy('started_on')->get();
        $this->assertCount(2, $stints);
        $this->assertSame('Claritas', $stints[0]->company);
        $this->assertSame('2026-02-28', $stints[0]->ended_on->toDateString());   // old company's last day = day BEFORE the change
        $this->assertSame('Nuren Sdn. Bhd.', $stints[1]->company);
        $this->assertSame('2026-03-01', $stints[1]->started_on->toDateString());  // new stint opens ON the effective date
        $this->assertNull($stints[1]->ended_on);
    }

    public function test_effective_date_before_current_stint_start_is_skipped(): void
    {
        $super = User::factory()->create(['role' => 'superadmin']);
        Employee::factory()->create(['user_id' => $super->id, 'company' => 'Claritas']); // sidebar needs an employee
        $emp = Employee::factory()->create(['company' => 'Claritas', 'start_date' => '2026-06-01']);
        $emp->ensureInitialCompanyStint(); // opens Claritas from 2026-06-01

        // Effective date BEFORE the current company's start → skipped, nothing changes.
        $result = $emp->changeCompanyEffective('Nuren Sdn. Bhd.', 'Nuren HQ, KL', Carbon::parse('2026-05-01'), $super->id);

        $this->assertSame('skipped_date', $result['status']);
        $this->assertSame('Claritas', $emp->fresh()->company);
        $this->assertCount(1, $emp->companyHistories()->get());
    }
}
