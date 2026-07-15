<?php

namespace Tests\Feature;

use App\Http\Middleware\EnforceTwoFactor;
use App\Models\Employee;
use App\Models\ExpenseClaim;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * HR "Reverse": un-approve a fully-approved claim. Behaves like a rejection (correctable),
 * but lands in the distinct `reversed` status, drops out of the approved export, and its
 * correction carries a distinct resubmission badge.
 */
class ClaimReverseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // The 2FA gate redirects the acting user (no challenge completed in the test session);
        // it's not what these tests exercise.
        $this->withoutMiddleware(EnforceTwoFactor::class);
    }

    private function approvedClaim(Employee $emp): ExpenseClaim
    {
        return ExpenseClaim::create([
            'employee_id' => $emp->id, 'company' => $emp->company, 'claim_number' => 'EC-2026-06-7001',
            'title' => 'T', 'year' => 2026, 'month' => 6, 'event' => 'Trip', 'status' => 'hr_approved',
            'hr_approved_at' => now(), 'processed_at' => now(),
            'total_amount' => 10, 'total_gst' => 0, 'total_with_gst' => 10, 'item_count' => 1,
        ]);
    }

    public function test_only_fully_approved_claims_can_be_reversed(): void
    {
        $hr = User::factory()->create(['role' => 'hr_manager']);
        $emp = Employee::factory()->create(['company' => 'Claritas']);

        $draft = ExpenseClaim::create([
            'employee_id' => $emp->id, 'claim_number' => 'EC-2026-06-7000', 'title' => 'T',
            'year' => 2026, 'month' => 6, 'status' => 'draft',
            'total_amount' => 0, 'total_gst' => 0, 'total_with_gst' => 0, 'item_count' => 0,
        ]);

        $this->actingAs($hr)->post(route('hr.claims.reverse', $draft), ['remarks' => 'no'])
            ->assertRedirect();
        $this->assertSame('draft', $draft->fresh()->status); // unchanged — not fully approved
    }

    public function test_reverse_freezes_claim_clears_processed_and_is_correctable(): void
    {
        Mail::fake();
        $hr = User::factory()->create(['role' => 'hr_manager']);
        $emp = Employee::factory()->create(['company' => 'Claritas']);
        $claim = $this->approvedClaim($emp);

        $this->actingAs($hr)->post(route('hr.claims.reverse', $claim), ['remarks' => 'Overruled by management']);

        $claim->refresh();
        $this->assertSame('reversed', $claim->status);
        $this->assertNull($claim->processed_at);                 // out of the approved ZIP
        $this->assertSame('Overruled by management', $claim->reverse_remarks);
        $this->assertNotNull($claim->reversed_at);
        $this->assertTrue($claim->canCorrect());                 // employee can correct, like a rejection
        $this->assertSame('Reversed', $claim->statusBadge()['label']);
    }

    public function test_correction_of_reversed_claim_gets_distinct_badge(): void
    {
        $emp = Employee::factory()->create(['company' => 'Claritas']);
        $reversed = $this->approvedClaim($emp);
        $reversed->update(['status' => 'reversed', 'reversed_at' => now(), 'processed_at' => null]);

        $correction = ExpenseClaim::create([
            'employee_id' => $emp->id, 'claim_number' => 'EC-2026-06-7002', 'title' => 'T',
            'year' => 2026, 'month' => 6, 'status' => 'submitted', 'correction_of_id' => $reversed->id,
            'total_amount' => 10, 'total_gst' => 0, 'total_with_gst' => 10, 'item_count' => 1,
        ]);

        $badge = $correction->resubmissionBadge();
        $this->assertNotNull($badge);
        $this->assertSame('Resubmitted · Reversal', $badge['label']);
        $this->assertStringContainsString('warning', $badge['class']); // distinct from the blue 'info' badge
    }
}
