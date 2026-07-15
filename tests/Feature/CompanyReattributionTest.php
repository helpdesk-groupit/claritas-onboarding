<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Services\CompanyAttributionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A back-dated company change must ripple through already-created records: claims
 * (by submission cycle), leave (by start_date) and tickets (by created_at) dated
 * on/after the effective date follow the new company; earlier ones stay put.
 */
class CompanyReattributionTest extends TestCase
{
    use RefreshDatabase;

    private Company $claritas;

    private Company $enlinea;

    private Company $nuren;

    private Employee $employee;

    private User $user;

    private int $leaveTypeId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->claritas = Company::create(['name' => 'Claritas Sdn Bhd']);
        $this->enlinea = Company::create(['name' => 'Enlinea Sdn Bhd']);
        $this->nuren = Company::create(['name' => 'Nuren Sdn Bhd']);

        $this->user = User::factory()->create(['role' => 'employee']);
        $this->employee = Employee::factory()->withUser($this->user)->create([
            'company' => $this->claritas->name,
            'start_date' => '2026-01-01',
        ]);

        $this->leaveTypeId = DB::table('leave_types')->insertGetId([
            'name' => 'Annual Leave', 'code' => 'AL', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeClaim(string $number, int $year, int $month, string $company): int
    {
        return DB::table('expense_claims')->insertGetId([
            'employee_id' => $this->employee->id, 'claim_number' => $number, 'title' => $number,
            'year' => $year, 'month' => $month, 'company' => $company,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeLeave(string $start, string $company): int
    {
        return DB::table('leave_applications')->insertGetId([
            'employee_id' => $this->employee->id, 'leave_type_id' => $this->leaveTypeId,
            'start_date' => $start, 'end_date' => $start, 'total_days' => 1, 'company' => $company,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeTicket(string $number, string $createdAt, int $companyId): int
    {
        return DB::table('tickets')->insertGetId([
            'ticket_number' => $number, 'user_id' => $this->user->id, 'department' => 'Group IT',
            'subject' => $number, 'description' => 'x', 'company_id' => $companyId,
            'created_at' => $createdAt, 'updated_at' => now(),
        ]);
    }

    public function test_back_dated_move_reattributes_claims_leave_and_tickets(): void
    {
        // All records seeded with the WRONG company so re-attribution has to fix them.
        $claimMar = $this->makeClaim('EC-2026-03-0001', 2026, 3, $this->enlinea->name);   // before → Claritas
        $claimJun = $this->makeClaim('EC-2026-06-0001', 2026, 6, $this->claritas->name);  // after  → Enlinea
        $leaveFeb = $this->makeLeave('2026-02-10', $this->enlinea->name);                 // before → Claritas
        $leaveJul = $this->makeLeave('2026-07-05', $this->claritas->name);                // after  → Enlinea
        $ticketMar = $this->makeTicket('TIC-2026-0001', '2026-03-15 09:00:00', $this->enlinea->id);  // before → Claritas
        $ticketJun = $this->makeTicket('TIC-2026-0002', '2026-06-20 09:00:00', $this->claritas->id); // after  → Enlinea

        // Back-dated move: Claritas → Enlinea effective 1 May, applied now.
        $this->employee->changeCompanyEffective($this->enlinea->name, null, Carbon::parse('2026-05-01'));

        app(CompanyAttributionService::class)->reattributeEmployee($this->employee->fresh());

        $this->assertSame($this->claritas->name, DB::table('expense_claims')->where('id', $claimMar)->value('company'));
        $this->assertSame($this->enlinea->name, DB::table('expense_claims')->where('id', $claimJun)->value('company'));
        $this->assertSame($this->claritas->name, DB::table('leave_applications')->where('id', $leaveFeb)->value('company'));
        $this->assertSame($this->enlinea->name, DB::table('leave_applications')->where('id', $leaveJul)->value('company'));
        $this->assertSame($this->claritas->id, (int) DB::table('tickets')->where('id', $ticketMar)->value('company_id'));
        $this->assertSame($this->enlinea->id, (int) DB::table('tickets')->where('id', $ticketJun)->value('company_id'));
    }

    public function test_dry_run_reports_but_does_not_write(): void
    {
        $claim = $this->makeClaim('EC-2026-06-0001', 2026, 6, $this->claritas->name);  // should become Enlinea
        $this->employee->changeCompanyEffective($this->enlinea->name, null, Carbon::parse('2026-05-01'));

        $counts = app(CompanyAttributionService::class)->reattributeEmployee($this->employee->fresh(), apply: false);

        $this->assertSame(1, $counts['claims'], 'Dry run should count the pending change.');
        $this->assertSame($this->claritas->name, DB::table('expense_claims')->where('id', $claim)->value('company'),
            'Dry run must not modify the stored company.');
    }

    public function test_records_before_any_move_are_left_on_the_current_company(): void
    {
        // No company change at all — a full recompute must be a no-op (idempotent).
        $claim = $this->makeClaim('EC-2026-06-0001', 2026, 6, $this->claritas->name);

        app(CompanyAttributionService::class)->reattributeEmployee($this->employee->fresh());

        $this->assertSame($this->claritas->name, DB::table('expense_claims')->where('id', $claim)->value('company'));
    }

    // ── Rewrite: correcting an accidental move via the bulk page ────────────

    public function test_preview_flags_a_before_current_start_date_as_rewrite(): void
    {
        // Accidental move Claritas → Enlinea effective 2026-06-02.
        $this->employee->changeCompanyEffective($this->enlinea->name, null, Carbon::parse('2026-06-02'));

        // Moving back to Claritas effective on/before the Enlinea start = a rewrite.
        $preview = $this->employee->fresh()->previewCompanyChange($this->claritas->name, Carbon::parse('2026-06-02'));
        $this->assertSame('rewrite', $preview['mode']);
        $this->assertCount(1, $preview['removes']);
        $this->assertSame($this->enlinea->name, $preview['removes']->first()->company);

        // A forward move (after the current start) is a plain append, not a rewrite.
        $this->assertSame('append',
            $this->employee->fresh()->previewCompanyChange($this->nuren->name, Carbon::parse('2026-07-01'))['mode']);
    }

    public function test_rewrite_from_cleanly_restores_previous_company_with_no_blip_and_reattributes(): void
    {
        $this->employee->changeCompanyEffective($this->enlinea->name, null, Carbon::parse('2026-06-02'));
        $claim = $this->makeClaim('EC-2026-07-0001', 2026, 7, $this->claritas->name);
        app(CompanyAttributionService::class)->reattributeEmployee($this->employee->fresh());
        $this->assertSame($this->enlinea->name, DB::table('expense_claims')->where('id', $claim)->value('company'));

        // Rewrite back to Claritas effective on the Enlinea start date.
        $result = $this->employee->fresh()->rewriteCompanyFrom($this->claritas->name, null, Carbon::parse('2026-06-02'));
        $this->assertSame('rewritten', $result['status']);
        $this->assertCount(1, $result['removed']);
        app(CompanyAttributionService::class)->reattributeEmployee($this->employee->fresh());

        // Single continuous open Claritas stint — the Enlinea blip is gone.
        $stints = $this->employee->fresh()->companyHistories;
        $this->assertCount(1, $stints);
        $this->assertSame($this->claritas->name, $stints->first()->company);
        $this->assertNull($stints->first()->ended_on);
        $this->assertSame($this->claritas->name, $this->employee->fresh()->company);
        $this->assertSame($this->claritas->name, DB::table('expense_claims')->where('id', $claim)->value('company'));
    }

    public function test_bulk_assign_holds_a_rewrite_for_confirmation_then_applies_when_confirmed(): void
    {
        $this->employee->changeCompanyEffective($this->enlinea->name, null, Carbon::parse('2026-06-02'));
        $admin = User::factory()->superadmin()->withTwoFactor()->create();

        // First submit (unconfirmed) — held for confirmation, nothing changes.
        $payload = [
            'employee_ids' => [$this->employee->id],
            'company' => $this->claritas->name,
            'effective_date' => '2026-06-02',
        ];
        $this->actingAs($admin)->post(route('superadmin.user-company-settings.bulk-assign'), $payload)
            ->assertRedirect()
            ->assertSessionHas('rewrite_confirm');
        $this->assertSame($this->enlinea->name, $this->employee->fresh()->company, 'Unconfirmed rewrite must not change anything.');

        // Second submit (confirmed) — applied.
        $this->actingAs($admin)->post(route('superadmin.user-company-settings.bulk-assign'), $payload + ['confirmed' => 1])
            ->assertRedirect()
            ->assertSessionHas('success');
        $this->assertSame($this->claritas->name, $this->employee->fresh()->company);
        $this->assertCount(1, $this->employee->fresh()->companyHistories);
    }

    public function test_bulk_assign_forward_move_needs_no_confirmation(): void
    {
        // Employee at Claritas since 2026-01-01; a forward move is a plain append.
        $admin = User::factory()->superadmin()->withTwoFactor()->create();

        $this->actingAs($admin)->post(route('superadmin.user-company-settings.bulk-assign'), [
            'employee_ids' => [$this->employee->id],
            'company' => $this->enlinea->name,
            'effective_date' => '2026-05-01',
        ])->assertRedirect()->assertSessionHas('success')->assertSessionMissing('rewrite_confirm');

        $this->assertSame($this->enlinea->name, $this->employee->fresh()->company);
    }
}
