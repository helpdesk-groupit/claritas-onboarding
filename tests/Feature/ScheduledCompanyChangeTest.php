<?php

namespace Tests\Feature;

use App\Http\Middleware\EnforceTwoFactor;
use App\Models\Company;
use App\Models\Employee;
use App\Models\ScheduledCompanyChange;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Future-dated company moves: scheduling from "User – Company Setting" stores intent without
 * touching the timeline, and `company:apply-scheduled` applies it on the effective date.
 */
class ScheduledCompanyChangeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(EnforceTwoFactor::class);
        Company::create(['name' => 'Nuren Sdn. Bhd.', 'address' => 'Nuren HQ, KL']);
    }

    private function superadmin(): User
    {
        $super = User::factory()->create(['role' => 'superadmin']);
        Employee::factory()->create(['user_id' => $super->id, 'company' => 'Claritas']); // sidebar needs an employee

        return $super;
    }

    public function test_future_date_schedules_change_without_touching_timeline(): void
    {
        $super = $this->superadmin();
        $emp = Employee::factory()->create(['company' => 'Claritas', 'start_date' => '2024-01-01']);
        $emp->ensureInitialCompanyStint();

        $future = Carbon::today()->addDays(10)->toDateString();

        $this->actingAs($super)->post(route('superadmin.user-company-settings.bulk-assign'), [
            'employee_ids' => [$emp->id],
            'company' => 'Nuren Sdn. Bhd.',
            'effective_date' => $future,
        ])->assertRedirect();

        // Timeline & live company are untouched — nothing applies until the date arrives.
        $emp->refresh();
        $this->assertSame('Claritas', $emp->company);
        $this->assertCount(1, $emp->companyHistories()->get());

        $this->assertDatabaseHas('scheduled_company_changes', [
            'employee_id' => $emp->id,
            'company' => 'Nuren Sdn. Bhd.',
            'effective_date' => $future,
            'status' => 'pending',
            'scheduled_by' => $super->id,
        ]);
    }

    public function test_scheduling_supersedes_a_prior_pending_change(): void
    {
        $super = $this->superadmin();
        $emp = Employee::factory()->create(['company' => 'Claritas', 'start_date' => '2024-01-01']);
        $emp->ensureInitialCompanyStint();

        $first = ScheduledCompanyChange::create([
            'employee_id' => $emp->id, 'company' => 'Nuren Sdn. Bhd.',
            'effective_date' => Carbon::today()->addDays(5)->toDateString(), 'status' => 'pending',
        ]);

        $this->actingAs($super)->post(route('superadmin.user-company-settings.bulk-assign'), [
            'employee_ids' => [$emp->id],
            'company' => 'Nuren Sdn. Bhd.',
            'effective_date' => Carbon::today()->addDays(20)->toDateString(),
        ])->assertRedirect();

        $this->assertSame('superseded', $first->fresh()->status);
        $this->assertSame(1, ScheduledCompanyChange::where('employee_id', $emp->id)->where('status', 'pending')->count());
    }

    public function test_apply_command_applies_a_due_change_and_splits_timeline(): void
    {
        $emp = Employee::factory()->create(['company' => 'Claritas', 'start_date' => '2024-01-01']);
        $emp->ensureInitialCompanyStint();

        $today = Carbon::today();
        ScheduledCompanyChange::create([
            'employee_id' => $emp->id, 'company' => 'Nuren Sdn. Bhd.', 'office_location' => 'Nuren HQ, KL',
            'effective_date' => $today->toDateString(), 'status' => 'pending',
        ]);

        $this->artisan('company:apply-scheduled')->assertExitCode(0);

        $emp->refresh();
        $this->assertSame('Nuren Sdn. Bhd.', $emp->company);
        $this->assertSame('Nuren HQ, KL', $emp->office_location);

        $stints = $emp->companyHistories()->orderBy('started_on')->get();
        $this->assertCount(2, $stints);
        $this->assertSame('Claritas', $stints[0]->company);
        $this->assertSame($today->copy()->subDay()->toDateString(), $stints[0]->ended_on->toDateString());
        $this->assertSame('Nuren Sdn. Bhd.', $stints[1]->company);
        $this->assertNull($stints[1]->ended_on);

        $this->assertDatabaseHas('scheduled_company_changes', [
            'employee_id' => $emp->id, 'status' => 'applied',
        ]);
    }

    public function test_apply_command_leaves_not_yet_due_changes_pending(): void
    {
        $emp = Employee::factory()->create(['company' => 'Claritas', 'start_date' => '2024-01-01']);
        $emp->ensureInitialCompanyStint();

        ScheduledCompanyChange::create([
            'employee_id' => $emp->id, 'company' => 'Nuren Sdn. Bhd.',
            'effective_date' => Carbon::today()->addDays(3)->toDateString(), 'status' => 'pending',
        ]);

        $this->artisan('company:apply-scheduled')->assertExitCode(0);

        $this->assertSame('Claritas', $emp->fresh()->company);
        $this->assertDatabaseHas('scheduled_company_changes', ['employee_id' => $emp->id, 'status' => 'pending']);
    }

    public function test_apply_command_is_idempotent(): void
    {
        $emp = Employee::factory()->create(['company' => 'Claritas', 'start_date' => '2024-01-01']);
        $emp->ensureInitialCompanyStint();

        ScheduledCompanyChange::create([
            'employee_id' => $emp->id, 'company' => 'Nuren Sdn. Bhd.', 'office_location' => 'Nuren HQ, KL',
            'effective_date' => Carbon::today()->toDateString(), 'status' => 'pending',
        ]);

        $this->artisan('company:apply-scheduled')->assertExitCode(0);
        $this->artisan('company:apply-scheduled')->assertExitCode(0); // rerun = no-op

        // No extra stint from the second run.
        $this->assertCount(2, $emp->companyHistories()->get());
    }

    public function test_apply_command_supersedes_change_for_offboarded_employee(): void
    {
        $emp = Employee::factory()->create(['company' => 'Claritas', 'start_date' => '2024-01-01', 'active_until' => Carbon::yesterday()->toDateString()]);
        $emp->ensureInitialCompanyStint();

        ScheduledCompanyChange::create([
            'employee_id' => $emp->id, 'company' => 'Nuren Sdn. Bhd.',
            'effective_date' => Carbon::today()->toDateString(), 'status' => 'pending',
        ]);

        $this->artisan('company:apply-scheduled')->assertExitCode(0);

        $this->assertSame('Claritas', $emp->fresh()->company);
        $this->assertDatabaseHas('scheduled_company_changes', ['employee_id' => $emp->id, 'status' => 'superseded']);
    }

    public function test_index_renders_the_scheduled_changes_panel(): void
    {
        $super = $this->superadmin();
        $emp = Employee::factory()->create(['full_name' => 'Ada Lovelace', 'company' => 'Claritas', 'start_date' => '2024-01-01']);

        ScheduledCompanyChange::create([
            'employee_id' => $emp->id, 'company' => 'Nuren Sdn. Bhd.',
            'effective_date' => Carbon::today()->addDays(7)->toDateString(), 'status' => 'pending', 'scheduled_by' => $super->id,
        ]);

        $this->actingAs($super)->get(route('superadmin.user-company-settings.index'))
            ->assertOk()
            ->assertSee('Scheduled changes')
            ->assertSee('Ada Lovelace')
            ->assertSee('Nuren Sdn. Bhd.');
    }

    public function test_superadmin_can_cancel_a_pending_change(): void
    {
        $super = $this->superadmin();
        $emp = Employee::factory()->create(['company' => 'Claritas', 'start_date' => '2024-01-01']);

        $change = ScheduledCompanyChange::create([
            'employee_id' => $emp->id, 'company' => 'Nuren Sdn. Bhd.',
            'effective_date' => Carbon::today()->addDays(5)->toDateString(), 'status' => 'pending',
        ]);

        $this->actingAs($super)->post(route('superadmin.user-company-settings.cancel-scheduled', $change))->assertRedirect();

        $change->refresh();
        $this->assertSame('cancelled', $change->status);
        $this->assertSame($super->id, $change->cancelled_by);
    }
}
