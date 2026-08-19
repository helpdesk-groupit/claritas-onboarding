<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\ExpenseCategory;
use App\Models\ExpenseClaim;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClaimReceiptMonthGuardTest extends TestCase
{
    use RefreshDatabase;

    private function draftClaim(User $user, Employee $owner): ExpenseClaim
    {
        return ExpenseClaim::create([
            'employee_id' => $owner->id,
            'year' => 2026, 'month' => 6,
            'claim_number' => 'EC-2026-06-'.random_int(8000, 8999),
            'title' => 'June claim', 'event' => 'June claim', 'status' => 'draft',
            'project_client' => 'Internal',
        ]);
    }

    private function category(): ExpenseCategory
    {
        return ExpenseCategory::create([
            'name' => 'Test', 'code' => 'TST-'.uniqid(), 'gl_code' => '900-000',
            'rate_type' => 'receipt', 'requires_receipt' => true, 'is_active' => true,
        ]);
    }

    public function test_receipt_from_another_month_is_rejected_with_guidance(): void
    {
        $user = User::factory()->create(['role' => 'employee']);
        $owner = Employee::factory()->withUser($user)->create();
        $claim = $this->draftClaim($user, $owner);
        $category = $this->category();

        // An April receipt added to a June claim → rejected, polite message names both months.
        $res = $this->actingAs($user)->postJson(route('user.claims.inline-add-item', $claim), [
            'expense_category_id' => $category->id,
            'description' => 'April taxi',
            'expense_date' => '2026-04-10',
            'amount' => 25,
        ]);

        $res->assertStatus(422)->assertJsonPath('ok', false);
        $this->assertStringContainsString('June 2026', $res->json('message'));
        $this->assertStringContainsString('April 2026', $res->json('message'));
        $this->assertDatabaseMissing('expense_claim_items', ['expense_claim_id' => $claim->id, 'description' => 'April taxi']);
    }

    public function test_scanned_receipt_date_out_of_month_is_rejected_even_when_expense_date_is_in_month(): void
    {
        // The reported gap: the user leaves the Date of Expense inside the claim month (June)
        // but the OCR read an April date into the read-only Category-C field (c_date). The
        // scanned receipt's own date must be enforced too, so this must NOT be addable.
        $user = User::factory()->create(['role' => 'employee']);
        $owner = Employee::factory()->withUser($user)->create();
        $claim = $this->draftClaim($user, $owner);
        $category = $this->category();

        $res = $this->actingAs($user)->postJson(route('user.claims.inline-add-item', $claim), [
            'expense_category_id' => $category->id,
            'description' => 'Grab ride',
            'expense_date' => '2026-06-15',   // in-month Date of Expense (would pass alone)
            'c_date' => '2026-04-28',         // but the scan read an April receipt
            'amount' => 32.20,
        ]);

        $res->assertStatus(422)->assertJsonPath('ok', false);
        $this->assertStringContainsString('June 2026', $res->json('message'));
        $this->assertStringContainsString('April 2026', $res->json('message'));
        $this->assertDatabaseMissing('expense_claim_items', ['expense_claim_id' => $claim->id, 'description' => 'Grab ride']);
    }

    /**
     * ── A receipt PAID before the period it pays for ─────────────────────────────────────
     *
     * The Jaya One season-parking receipt redesigned in Aug 2026 is dated 30/07/2026 and
     * carries the line "Unbilled  1/08/2026 - 31/08/2026". It is an August expense settled in
     * July, and the scanned-date guard rejected it on a date the employee could not change —
     * the receipt is simply not dated in the month it belongs to.
     */
    private function augustClaim(Employee $owner): ExpenseClaim
    {
        return ExpenseClaim::create([
            'employee_id' => $owner->id,
            'year' => 2026, 'month' => 8,
            'claim_number' => 'EC-2026-08-'.random_int(8000, 8999),
            'title' => 'August claim', 'event' => 'August claim', 'status' => 'draft',
            'project_client' => 'Internal',
        ]);
    }

    public function test_a_receipt_paid_before_the_period_it_covers_is_claimed_under_that_period(): void
    {
        $this->travelTo('2026-08-19');

        $user = User::factory()->create(['role' => 'employee']);
        $owner = Employee::factory()->withUser($user)->create();
        $claim = $this->augustClaim($owner);
        $category = $this->category();

        $res = $this->actingAs($user)->postJson(route('user.claims.inline-add-item', $claim), [
            'expense_category_id' => $category->id,
            'description' => 'Season parking',
            'expense_date' => '2026-08-01',    // the first day of the period it pays for
            'c_date' => '2026-07-30',          // ...but the receipt was settled in July
            'c_period_start' => '2026-08-01',
            'c_period_end' => '2026-08-31',
            'amount' => 190,
        ]);

        $res->assertStatus(200)->assertJsonPath('ok', true);

        // The period is STORED, because it is the only thing on the record explaining why a
        // July-dated receipt sits in an August claim — the approver reads it off the report.
        $item = $claim->items()->firstWhere('description', 'Season parking');
        $this->assertNotNull($item);
        $this->assertSame('2026-07-30', $item->ocr_details['date']);
        $this->assertSame('2026-08-01', $item->ocr_details['period_start']);
        $this->assertSame('2026-08-31', $item->ocr_details['period_end']);
    }

    public function test_a_coverage_period_that_never_reaches_the_claim_month_is_still_rejected(): void
    {
        $this->travelTo('2026-08-19');

        $user = User::factory()->create(['role' => 'employee']);
        $owner = Employee::factory()->withUser($user)->create();
        $claim = $this->augustClaim($owner);
        $category = $this->category();

        // A SEPTEMBER season pass bought in July belongs to neither July nor August.
        $res = $this->actingAs($user)->postJson(route('user.claims.inline-add-item', $claim), [
            'expense_category_id' => $category->id,
            'description' => 'Season parking',
            'expense_date' => '2026-08-01',
            'c_date' => '2026-07-30',
            'c_period_start' => '2026-09-01',
            'c_period_end' => '2026-09-30',
            'amount' => 190,
        ]);

        $res->assertStatus(422)->assertJsonPath('ok', false);
        // The message must name the COVERAGE, not just the payment date — blaming a date the
        // user can see is not the whole story is what makes them retry the same upload.
        $this->assertStringContainsString('covers', $res->json('message'));
        $this->assertStringContainsString('Sep 2026', $res->json('message'));
        $this->assertStringContainsString('September 2026', $res->json('message'));
        $this->assertDatabaseMissing('expense_claim_items', ['expense_claim_id' => $claim->id, 'description' => 'Season parking']);
    }

    public function test_an_unbelievable_coverage_period_does_not_wave_a_wrong_month_receipt_through(): void
    {
        $this->travelTo('2026-08-19');

        $user = User::factory()->create(['role' => 'employee']);
        $owner = Employee::factory()->withUser($user)->create();
        $category = $this->category();

        // Half a range, and a decade-long one. Both would otherwise be a way to file any
        // receipt under any month by asserting a period the document does not carry.
        $cases = [
            'half-read' => ['c_period_start' => '2026-08-01', 'c_period_end' => ''],
            'decade' => ['c_period_start' => '2020-01-01', 'c_period_end' => '2030-12-31'],
        ];

        foreach ($cases as $label => $period) {
            $claim = $this->augustClaim($owner);
            $res = $this->actingAs($user)->postJson(route('user.claims.inline-add-item', $claim), array_merge([
                'expense_category_id' => $category->id,
                'description' => 'Bogus '.$label,
                'expense_date' => '2026-08-01',
                'c_date' => '2026-07-30',
                'amount' => 190,
            ], $period));

            $res->assertStatus(422)->assertJsonPath('ok', false);
            $this->assertDatabaseMissing('expense_claim_items', ['expense_claim_id' => $claim->id, 'description' => 'Bogus '.$label]);
        }
    }

    public function test_receipt_from_the_claim_month_is_accepted(): void
    {
        $user = User::factory()->create(['role' => 'employee']);
        $owner = Employee::factory()->withUser($user)->create();
        $claim = $this->draftClaim($user, $owner);
        $category = $this->category();

        $res = $this->actingAs($user)->postJson(route('user.claims.inline-add-item', $claim), [
            'expense_category_id' => $category->id,
            'description' => 'June taxi',
            'expense_date' => '2026-06-10',
            'amount' => 25,
        ]);

        $res->assertStatus(200)->assertJsonPath('ok', true);
        $this->assertDatabaseHas('expense_claim_items', ['expense_claim_id' => $claim->id, 'description' => 'June taxi']);
    }
}
