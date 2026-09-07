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

    /**
     * ── Manual override for the covered period ───────────────────────────────────────────
     *
     * The coverage escape is only reachable if the scan reads the period. When it doesn't —
     * a poor photo, an operator whose receipt is laid out differently — the employee was
     * blocked with no way to state the period themselves. These pin the hand-entry path.
     */
    public function test_a_hand_entered_period_rescues_a_receipt_the_scan_could_not_read(): void
    {
        $this->travelTo('2026-08-19');

        $user = User::factory()->create(['role' => 'employee']);
        $owner = Employee::factory()->withUser($user)->create();
        $claim = $this->augustClaim($owner);
        $category = $this->category();

        $res = $this->actingAs($user)->postJson(route('user.claims.inline-add-item', $claim), [
            'expense_category_id' => $category->id,
            'description' => 'Season parking',
            'expense_date' => '2026-08-01',
            'c_date' => '2026-07-30',       // the scan read the payment date...
            'c_period_start' => '2026-08-01', // ...but the employee typed the period themselves
            'c_period_end' => '2026-08-31',
            'c_period_manual' => '1',
            'amount' => 190,
        ]);

        $res->assertStatus(200)->assertJsonPath('ok', true);

        // Stamped as hand-entered — the report prints this period as the justification for
        // accepting an out-of-month receipt, so it must never read as machine-read.
        $item = $claim->items()->firstWhere('description', 'Season parking');
        $this->assertSame('2026-08-01', $item->ocr_details['period_start']);
        $this->assertSame('manual', $item->ocr_details['period_source']);
    }

    public function test_a_scan_read_period_is_not_stamped_as_hand_entered(): void
    {
        $this->travelTo('2026-08-19');

        $user = User::factory()->create(['role' => 'employee']);
        $owner = Employee::factory()->withUser($user)->create();
        $claim = $this->augustClaim($owner);
        $category = $this->category();

        $this->actingAs($user)->postJson(route('user.claims.inline-add-item', $claim), [
            'expense_category_id' => $category->id,
            'description' => 'Season parking',
            'expense_date' => '2026-08-01',
            'c_date' => '2026-07-30',
            'c_period_start' => '2026-08-01',
            'c_period_end' => '2026-08-31',
            'amount' => 190,
        ])->assertStatus(200);

        $item = $claim->items()->firstWhere('description', 'Season parking');
        $this->assertArrayNotHasKey('period_source', $item->ocr_details);
    }

    public function test_a_half_typed_period_is_explained_rather_than_silently_dropped(): void
    {
        $this->travelTo('2026-08-19');

        $user = User::factory()->create(['role' => 'employee']);
        $owner = Employee::factory()->withUser($user)->create();
        $claim = $this->augustClaim($owner);
        $category = $this->category();

        $res = $this->actingAs($user)->postJson(route('user.claims.inline-add-item', $claim), [
            'expense_category_id' => $category->id,
            'description' => 'Season parking',
            'expense_date' => '2026-08-01',
            'c_date' => '2026-07-30',
            'c_period_start' => '2026-08-01',   // start only — they forgot the end
            'c_period_end' => '',
            'c_period_manual' => '1',
            'amount' => 190,
        ]);

        $res->assertStatus(422)->assertJsonPath('ok', false);
        // Must name the field they were filling in, NOT the receipt's printed date — that
        // message would say nothing about what they got wrong.
        $this->assertStringContainsString('BOTH', $res->json('message'));
        $this->assertStringNotContainsString('30 Jul', $res->json('message'));
        $this->assertDatabaseMissing('expense_claim_items', ['expense_claim_id' => $claim->id, 'description' => 'Season parking']);
    }

    public function test_an_over_long_typed_period_is_refused_with_a_reason(): void
    {
        $this->travelTo('2026-08-19');

        $user = User::factory()->create(['role' => 'employee']);
        $owner = Employee::factory()->withUser($user)->create();
        $claim = $this->augustClaim($owner);
        $category = $this->category();

        $res = $this->actingAs($user)->postJson(route('user.claims.inline-add-item', $claim), [
            'expense_category_id' => $category->id,
            'description' => 'Season parking',
            'expense_date' => '2026-08-01',
            'c_date' => '2026-07-30',
            'c_period_start' => '2020-01-01',
            'c_period_end' => '2030-12-31',
            'c_period_manual' => '1',
            'amount' => 190,
        ]);

        $res->assertStatus(422)->assertJsonPath('ok', false);
        $this->assertStringContainsString('longer than a year', $res->json('message'));
    }

    public function test_a_bad_period_from_the_sca_n_never_hard_blocks_the_add(): void
    {
        $this->travelTo('2026-08-19');

        $user = User::factory()->create(['role' => 'employee']);
        $owner = Employee::factory()->withUser($user)->create();
        $claim = $this->augustClaim($owner);
        $category = $this->category();

        // Same nonsense range, but NOT flagged as typed — it came from OCR. OCR must never
        // hard-block a claim, so this falls through to the ordinary wrong-month message
        // rather than an input error about a field the employee never touched.
        $res = $this->actingAs($user)->postJson(route('user.claims.inline-add-item', $claim), [
            'expense_category_id' => $category->id,
            'description' => 'Season parking',
            'expense_date' => '2026-08-01',
            'c_date' => '2026-07-30',
            'c_period_start' => '2020-01-01',
            'c_period_end' => '2030-12-31',
            'amount' => 190,
        ]);

        $res->assertStatus(422)->assertJsonPath('ok', false);
        $this->assertStringNotContainsString('longer than a year', $res->json('message'));
        $this->assertStringContainsString('30 Jul 2026', $res->json('message'));
    }

    public function test_a_typed_period_that_does_not_reach_the_month_is_still_refused(): void
    {
        $this->travelTo('2026-08-19');

        $user = User::factory()->create(['role' => 'employee']);
        $owner = Employee::factory()->withUser($user)->create();
        $claim = $this->augustClaim($owner);
        $category = $this->category();

        // Hand entry is an override for the READING, never for the rule.
        $res = $this->actingAs($user)->postJson(route('user.claims.inline-add-item', $claim), [
            'expense_category_id' => $category->id,
            'description' => 'Season parking',
            'expense_date' => '2026-08-01',
            'c_date' => '2026-07-30',
            'c_period_start' => '2026-09-01',
            'c_period_end' => '2026-09-30',
            'c_period_manual' => '1',
            'amount' => 190,
        ]);

        $res->assertStatus(422)->assertJsonPath('ok', false);
        $this->assertStringContainsString('covers', $res->json('message'));
        $this->assertDatabaseMissing('expense_claim_items', ['expense_claim_id' => $claim->id, 'description' => 'Season parking']);
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

    /**
     * ── A receipt date the SCAN got wrong ────────────────────────────────────────────────
     *
     * Reported 2026-09-07: a POPULAR Book Co. thermal receipt printed "Date: 04/09/26" was
     * read as August, so a September receipt could not be added to a September claim. The
     * employee had no way out — ocrReceiptDateOutOfPeriod() judges c_date, which was
     * read-only, and correcting the Date of Expense does not reach that guard. The printed
     * date is now correctable, on the same terms as the covered period: the employee may say
     * what the receipt reads, and the report records that a person said it.
     */
    public function test_a_misread_receipt_date_can_be_corrected_by_hand(): void
    {
        $user = User::factory()->create(['role' => 'employee']);
        $owner = Employee::factory()->withUser($user)->create();
        $claim = $this->draftClaim($user, $owner);
        $category = $this->category();

        $res = $this->actingAs($user)->postJson(route('user.claims.inline-add-item', $claim), [
            'expense_category_id' => $category->id,
            'description' => 'Stationery',
            'expense_date' => '2026-06-04',
            'c_date' => '2026-06-04',   // corrected from the scan's wrong month
            'c_date_manual' => '1',
            'amount' => 30.60,
        ]);

        $res->assertStatus(200)->assertJsonPath('ok', true);
        $this->assertDatabaseHas('expense_claim_items', ['expense_claim_id' => $claim->id, 'description' => 'Stationery']);
    }

    /** The approver holds the receipt image, so they must be able to tell a correction from a reading. */
    public function test_a_hand_corrected_receipt_date_is_recorded_as_entered_by_hand(): void
    {
        $user = User::factory()->create(['role' => 'employee']);
        $owner = Employee::factory()->withUser($user)->create();
        $claim = $this->draftClaim($user, $owner);
        $category = $this->category();

        $this->actingAs($user)->postJson(route('user.claims.inline-add-item', $claim), [
            'expense_category_id' => $category->id,
            'description' => 'Corrected date',
            'expense_date' => '2026-06-04',
            'c_date' => '2026-06-04',
            'c_date_manual' => '1',
            'amount' => 12,
        ])->assertStatus(200);

        $item = $claim->fresh('items')->items()->where('description', 'Corrected date')->first();
        $this->assertSame('manual', $item->ocr_details['date_source'] ?? null);
    }

    /** A date the scan read is NOT labelled as typed — the mark has to mean something. */
    public function test_a_scanned_receipt_date_is_not_labelled_as_hand_entered(): void
    {
        $user = User::factory()->create(['role' => 'employee']);
        $owner = Employee::factory()->withUser($user)->create();
        $claim = $this->draftClaim($user, $owner);
        $category = $this->category();

        $this->actingAs($user)->postJson(route('user.claims.inline-add-item', $claim), [
            'expense_category_id' => $category->id,
            'description' => 'Scanned date',
            'expense_date' => '2026-06-04',
            'c_date' => '2026-06-04',
            'amount' => 12,
        ])->assertStatus(200);

        $item = $claim->fresh('items')->items()->where('description', 'Scanned date')->first();
        $this->assertArrayNotHasKey('date_source', $item->ocr_details ?? []);
    }

    /**
     * The flag is PROVENANCE, never permission. If claiming "I typed this" also skipped the
     * month check, the guard would be bypassable from the browser by anyone who read the form.
     */
    public function test_claiming_a_date_was_typed_does_not_bypass_the_month_guard(): void
    {
        $user = User::factory()->create(['role' => 'employee']);
        $owner = Employee::factory()->withUser($user)->create();
        $claim = $this->draftClaim($user, $owner);
        $category = $this->category();

        $res = $this->actingAs($user)->postJson(route('user.claims.inline-add-item', $claim), [
            'expense_category_id' => $category->id,
            'description' => 'Smuggled',
            'expense_date' => '2026-06-15',
            'c_date' => '2026-04-28',
            'c_date_manual' => '1',
            'amount' => 40,
        ]);

        $res->assertStatus(422)->assertJsonPath('ok', false);
        $this->assertDatabaseMissing('expense_claim_items', ['expense_claim_id' => $claim->id, 'description' => 'Smuggled']);
    }

    /** A block with no route out is what made this a support ticket rather than a self-fix. */
    public function test_the_block_message_names_the_field_that_fixes_a_misread_date(): void
    {
        $user = User::factory()->create(['role' => 'employee']);
        $owner = Employee::factory()->withUser($user)->create();
        $claim = $this->draftClaim($user, $owner);
        $category = $this->category();

        $res = $this->actingAs($user)->postJson(route('user.claims.inline-add-item', $claim), [
            'expense_category_id' => $category->id,
            'description' => 'Wrong month',
            'expense_date' => '2026-06-15',
            'c_date' => '2026-04-28',
            'amount' => 40,
        ]);

        $res->assertStatus(422);
        $this->assertStringContainsString('Date on receipt', $res->json('message'));
    }

    /**
     * The fix that actually unblocked the employee.
     *
     * The server always accepted whatever c_date the browser sent — the block was that the
     * field was rendered readonly, so there was no way to send a corrected one. This asserts
     * the control exists and is editable; without it the rest of this behaviour is
     * unreachable from the UI and the tests above pass against a form nobody can use.
     */
    public function test_the_printed_receipt_date_is_an_editable_control_on_the_claim_form(): void
    {
        $user = User::factory()->create(['role' => 'employee']);
        $owner = Employee::factory()->withUser($user)->create();
        $claim = $this->draftClaim($user, $owner);
        $this->category();

        // The inline editor renders only for a draft explicitly opened with ?open — on a plain
        // load the form is empty, so without this the assertions below would measure a page
        // that legitimately has no receipt-details panel at all.
        $html = $this->actingAs($user)
            ->get(route('user.claims.index', ['open' => $claim->id]))
            ->assertStatus(200)->getContent();

        $this->assertMatchesRegularExpression(
            '/<input[^>]*type="date"[^>]*cc-c-date/',
            $html,
            'The receipt date is not rendered as an editable date input.'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/<input[^>]*cc-c-date[^>]*readonly/',
            $html,
            'The receipt date is still readonly — a misread date cannot be corrected.'
        );
    }
}
