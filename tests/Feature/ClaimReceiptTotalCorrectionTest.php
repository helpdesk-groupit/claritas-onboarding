<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\ExpenseCategory;
use App\Models\ExpenseClaim;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A receipt TOTAL the scan got wrong.
 *
 * Reported 2026-09-08: an AEON BIG receipt printed "TOTAL 19.65" (7.76 + 11.90, less a 1-cent
 * rounding adjustment) was read as 13.03. The over-claim guard compares the claimed amount
 * against that reading, so claiming the RM 19.65 actually paid was hard-blocked — and "Total
 * paid (RM)" was read-only, so nothing on the form could correct it. The only ways out were to
 * under-claim by RM 6.62 or to abandon the line: the same shape as the misread printed date
 * fixed the day before, on a field where being wrong costs money rather than a month.
 *
 * The printed total is now correctable on exactly the terms the printed date is: the employee
 * may say what the receipt reads, and the record says that a person said it.
 */
class ClaimReceiptTotalCorrectionTest extends TestCase
{
    use RefreshDatabase;

    private function draftClaim(Employee $owner): ExpenseClaim
    {
        return ExpenseClaim::create([
            'employee_id' => $owner->id,
            'year' => 2026, 'month' => 6,
            'claim_number' => 'EC-2026-06-'.random_int(8000, 8999),
            'title' => 'June claim', 'event' => 'June claim', 'status' => 'draft',
            'project_client' => 'Internal',
        ]);
    }

    /** A plain receipt category — the amount is typed and counter-checked against the receipt. */
    private function category(): ExpenseCategory
    {
        return ExpenseCategory::create([
            'name' => 'Office Food & Refreshment', 'code' => 'TST-'.uniqid(), 'gl_code' => '922-000',
            'rate_type' => 'receipt', 'requires_receipt' => true, 'is_active' => true,
        ]);
    }

    /**
     * A CAPPED category (Medical, Optical & Dental, Support Allowance, season parking). Here the
     * receipt total is not a ceiling to check against — it IS the claimed amount, capped to the
     * remaining allowance, which is why a misread matters even more on this path.
     */
    private function cappedCategory(float $limit = 500): ExpenseCategory
    {
        return ExpenseCategory::create([
            'name' => 'Optical & Dental', 'code' => 'CAP-'.uniqid(), 'gl_code' => '933-000',
            'rate_type' => 'receipt', 'requires_receipt' => true, 'is_active' => true,
            'monthly_limit' => $limit, 'limit_period' => 'annual',
        ]);
    }

    /** @return array{0: User, 1: Employee} */
    private function employee(): array
    {
        $user = User::factory()->create(['role' => 'employee']);

        return [$user, Employee::factory()->withUser($user)->create()];
    }

    // ── The block, and the way out ──────────────────────────────────────────────────────────

    /** The reported failure: the true amount is refused because the scan read a smaller total. */
    public function test_a_misread_total_blocks_the_amount_actually_paid(): void
    {
        [$user, $owner] = $this->employee();
        $claim = $this->draftClaim($owner);

        $res = $this->actingAs($user)->postJson(route('user.claims.inline-add-item', $claim), [
            'expense_category_id' => $this->category()->id,
            'description' => 'Fruits',
            'expense_date' => '2026-06-19',
            'c_total' => '13.03',   // what the scan read
            'amount' => 19.65,      // what the receipt says
        ]);

        $res->assertStatus(422)->assertJsonPath('ok', false);
        $this->assertDatabaseMissing('expense_claim_items', ['expense_claim_id' => $claim->id, 'description' => 'Fruits']);
    }

    /**
     * A block with no route out is what made this a support ticket rather than a self-fix.
     * Lowering the amount to a figure the receipt does not carry is under-claiming, not a fix,
     * so the refusal has to name the field that actually clears it.
     */
    public function test_the_block_message_names_the_field_that_fixes_a_misread_total(): void
    {
        [$user, $owner] = $this->employee();
        $claim = $this->draftClaim($owner);

        $res = $this->actingAs($user)->postJson(route('user.claims.inline-add-item', $claim), [
            'expense_category_id' => $this->category()->id,
            'description' => 'Fruits',
            'expense_date' => '2026-06-19',
            'c_total' => '13.03',
            'amount' => 19.65,
        ]);

        $res->assertStatus(422);
        $this->assertStringContainsString('Total paid', $res->json('errors.amount'));
        $this->assertStringContainsString('Receipt details', $res->json('errors.amount'));
    }

    /** The fix: correcting the printed total lets the amount the employee actually paid through. */
    public function test_a_corrected_total_lets_the_real_amount_through(): void
    {
        [$user, $owner] = $this->employee();
        $claim = $this->draftClaim($owner);

        $res = $this->actingAs($user)->postJson(route('user.claims.inline-add-item', $claim), [
            'expense_category_id' => $this->category()->id,
            'description' => 'Fruits',
            'expense_date' => '2026-06-19',
            'c_total' => '19.65',        // corrected from the scan's 13.03
            'c_total_manual' => '1',
            'amount' => 19.65,
        ]);

        $res->assertStatus(200)->assertJsonPath('ok', true);
        $this->assertDatabaseHas('expense_claim_items', [
            'expense_claim_id' => $claim->id, 'description' => 'Fruits', 'amount' => 19.65,
        ]);
    }

    // ── Provenance ──────────────────────────────────────────────────────────────────────────

    /** The approver holds the receipt image, so they must be able to tell a correction from a reading. */
    public function test_a_hand_corrected_total_is_recorded_as_entered_by_hand(): void
    {
        [$user, $owner] = $this->employee();
        $claim = $this->draftClaim($owner);

        $this->actingAs($user)->postJson(route('user.claims.inline-add-item', $claim), [
            'expense_category_id' => $this->category()->id,
            'description' => 'Corrected total',
            'expense_date' => '2026-06-19',
            'c_total' => '19.65',
            'c_total_manual' => '1',
            'amount' => 19.65,
        ])->assertStatus(200);

        $item = $claim->fresh('items')->items()->where('description', 'Corrected total')->first();
        $this->assertSame('manual', $item->ocr_details['total_source'] ?? null);
    }

    /** A total the scan read is NOT labelled as typed — the mark has to mean something. */
    public function test_a_scanned_total_is_not_labelled_as_hand_entered(): void
    {
        [$user, $owner] = $this->employee();
        $claim = $this->draftClaim($owner);

        $this->actingAs($user)->postJson(route('user.claims.inline-add-item', $claim), [
            'expense_category_id' => $this->category()->id,
            'description' => 'Scanned total',
            'expense_date' => '2026-06-19',
            'c_total' => '19.65',
            'amount' => 19.65,
        ])->assertStatus(200);

        $item = $claim->fresh('items')->items()->where('description', 'Scanned total')->first();
        $this->assertArrayNotHasKey('total_source', $item->ocr_details ?? []);
    }

    /** The copy of record the approver signs has to carry the mark, not just the database. */
    public function test_the_signed_report_says_a_corrected_total_was_entered_by_hand(): void
    {
        [$user, $owner] = $this->employee();
        $claim = $this->draftClaim($owner);

        $this->actingAs($user)->postJson(route('user.claims.inline-add-item', $claim), [
            'expense_category_id' => $this->category()->id,
            'description' => 'Fruits',
            'expense_date' => '2026-06-19',
            'c_total' => '19.65',
            'c_total_manual' => '1',
            'amount' => 19.65,
        ])->assertStatus(200);

        $claim = $claim->fresh(['items.category', 'employee']);
        $html = view('user.claims.report-pdf', [
            'claim' => $claim,
            'company' => Company::forName($claim->resolvedCompany()),
            'items' => $claim->items,
        ])->render();

        $this->assertStringContainsString('Total paid:', $html);
        $this->assertStringContainsString('(entered by hand)', $html);
        // The Blade `@`-gluing family: the marker is built with directives sitting after a `}}`
        // or a `)`, so a mis-placed one would leak as literal text rather than throw.
        $this->assertStringNotContainsString('@if', $html);
        $this->assertStringNotContainsString('@endif', $html);
    }

    /** ...and must NOT say it on a total the scan read, or the mark stops meaning anything. */
    public function test_the_signed_report_does_not_mark_a_scanned_total(): void
    {
        [$user, $owner] = $this->employee();
        $claim = $this->draftClaim($owner);

        $this->actingAs($user)->postJson(route('user.claims.inline-add-item', $claim), [
            'expense_category_id' => $this->category()->id,
            'description' => 'Fruits',
            'expense_date' => '2026-06-19',
            'c_total' => '19.65',
            'amount' => 19.65,
        ])->assertStatus(200);

        $claim = $claim->fresh(['items.category', 'employee']);
        $html = view('user.claims.report-pdf', [
            'claim' => $claim,
            'company' => Company::forName($claim->resolvedCompany()),
            'items' => $claim->items,
        ])->render();

        $this->assertStringContainsString('Total paid:', $html);
        $this->assertStringNotContainsString('(entered by hand)', $html);
    }

    // ── Provenance is not permission ────────────────────────────────────────────────────────

    /**
     * The flag says WHO authored the figure; it never decides whether the figure is accepted.
     * If claiming "I typed this" also skipped the counter-check, the guard would be bypassable
     * from the browser by anyone who read the form.
     */
    public function test_claiming_a_total_was_typed_does_not_bypass_the_over_claim_guard(): void
    {
        [$user, $owner] = $this->employee();
        $claim = $this->draftClaim($owner);

        $res = $this->actingAs($user)->postJson(route('user.claims.inline-add-item', $claim), [
            'expense_category_id' => $this->category()->id,
            'description' => 'Smuggled',
            'expense_date' => '2026-06-19',
            'c_total' => '13.03',
            'c_total_manual' => '1',   // "a person typed this" — still 13.03, still the ceiling
            'amount' => 19.65,
        ]);

        $res->assertStatus(422)->assertJsonPath('ok', false);
        $this->assertDatabaseMissing('expense_claim_items', ['expense_claim_id' => $claim->id, 'description' => 'Smuggled']);
    }

    // ── A typed figure that can't be believed ───────────────────────────────────────────────

    /**
     * Silently ignoring "RM19.65" would drop the correction, re-block the employee against the
     * misread total, and report it as a problem with the AMOUNT — saying nothing about the field
     * they were actually typing in.
     */
    public function test_a_typed_total_that_is_not_a_number_is_explained_rather_than_dropped(): void
    {
        [$user, $owner] = $this->employee();
        $claim = $this->draftClaim($owner);

        $res = $this->actingAs($user)->postJson(route('user.claims.inline-add-item', $claim), [
            'expense_category_id' => $this->category()->id,
            'description' => 'Fruits',
            'expense_date' => '2026-06-19',
            'c_total' => 'RM19.65',
            'c_total_manual' => '1',
            'amount' => 19.65,
        ]);

        $res->assertStatus(422)->assertJsonPath('ok', false);
        $this->assertStringContainsString('19.65', (string) $res->json('errors.c_total'));
        $this->assertDatabaseMissing('expense_claim_items', ['expense_claim_id' => $claim->id, 'description' => 'Fruits']);
    }

    /**
     * "19,65" is the one a strict rule earns its keep on: read as a number it is 19, and the
     * report would then print RM 19.00 against a receipt plainly reading 19.65. Guessing which
     * of 19.65 and 1965 was meant is exactly the silent re-interpretation this module refuses.
     */
    public function test_a_comma_decimal_is_refused_rather_than_read_as_a_different_figure(): void
    {
        [$user, $owner] = $this->employee();
        $claim = $this->draftClaim($owner);

        $res = $this->actingAs($user)->postJson(route('user.claims.inline-add-item', $claim), [
            'expense_category_id' => $this->category()->id,
            'description' => 'Fruits',
            'expense_date' => '2026-06-19',
            'c_total' => '19,65',
            'c_total_manual' => '1',
            'amount' => 19.65,
        ]);

        $res->assertStatus(422)->assertJsonPath('ok', false);
        $this->assertDatabaseMissing('expense_claim_items', ['expense_claim_id' => $claim->id, 'description' => 'Fruits']);
    }

    /**
     * The same rule must NOT fire on a value the scan produced. OCR fails open everywhere in
     * this module — a reading it could not make must never be the thing that blocks a claim.
     */
    public function test_an_unreadable_scanned_total_still_does_not_block_the_claim(): void
    {
        [$user, $owner] = $this->employee();
        $claim = $this->draftClaim($owner);

        $res = $this->actingAs($user)->postJson(route('user.claims.inline-add-item', $claim), [
            'expense_category_id' => $this->category()->id,
            'description' => 'Fruits',
            'expense_date' => '2026-06-19',
            'c_total' => 'RM19.65',   // no c_total_manual — this is what the scan handed over
            'amount' => 19.65,
        ]);

        $res->assertStatus(200)->assertJsonPath('ok', true);
    }

    /** Clearing the field again claims no ceiling at all — nothing to complain about. */
    public function test_clearing_a_typed_total_is_not_an_error(): void
    {
        [$user, $owner] = $this->employee();
        $claim = $this->draftClaim($owner);

        $res = $this->actingAs($user)->postJson(route('user.claims.inline-add-item', $claim), [
            'expense_category_id' => $this->category()->id,
            'description' => 'Fruits',
            'expense_date' => '2026-06-19',
            'c_total' => '',
            'c_total_manual' => '1',
            'amount' => 19.65,
        ]);

        $res->assertStatus(200)->assertJsonPath('ok', true);
        $item = $claim->fresh('items')->items()->where('description', 'Fruits')->first();
        $this->assertArrayNotHasKey('total_source', $item->ocr_details ?? []);
    }

    // ── The capped path, where the total IS the amount ───────────────────────────────────────

    /**
     * On a capped category the claimed amount is min(receipt total, remaining allowance) — it is
     * not typed. A misread total there does not block anything; it silently SHORT-PAYS, which is
     * worse, because nothing on the form or the report says a figure was lost. The correction has
     * to reach that path too.
     */
    public function test_a_corrected_total_raises_a_capped_claim_to_what_was_paid(): void
    {
        [$user, $owner] = $this->employee();
        $claim = $this->draftClaim($owner);

        $this->actingAs($user)->postJson(route('user.claims.inline-add-item', $claim), [
            'expense_category_id' => $this->cappedCategory()->id,
            'description' => 'Spectacles',
            'expense_date' => '2026-06-19',
            'c_total' => '19.65',
            'c_total_manual' => '1',
            'amount' => 13.03,   // the browser's stale derived figure — the total decides here
        ])->assertStatus(200);

        $item = $claim->fresh('items')->items()->where('description', 'Spectacles')->first();
        $this->assertSame('19.65', number_format((float) $item->amount, 2));
        $this->assertSame('manual', $item->ocr_details['total_source'] ?? null);
    }

    /** The cap still wins over a corrected total — the correction says what was paid, not what is claimable. */
    public function test_a_corrected_total_is_still_capped_to_the_remaining_allowance(): void
    {
        [$user, $owner] = $this->employee();
        $claim = $this->draftClaim($owner);

        $this->actingAs($user)->postJson(route('user.claims.inline-add-item', $claim), [
            'expense_category_id' => $this->cappedCategory(80)->id,
            'description' => 'Season parking',
            'expense_date' => '2026-06-19',
            'c_total' => '250.00',
            'c_total_manual' => '1',
            'amount' => 250,
        ])->assertStatus(200);

        $item = $claim->fresh('items')->items()->where('description', 'Season parking')->first();
        $this->assertSame('80.00', number_format((float) $item->total_with_gst, 2));
    }

    // ── Editing an item ─────────────────────────────────────────────────────────────────────

    /**
     * Editing an item means re-uploading and re-scanning it, so Category C is rebuilt from
     * scratch every time — which is exactly how a hand-corrected total could be silently
     * upgraded to "read from the receipt" on a claim nobody touched the total on again.
     */
    public function test_editing_an_item_keeps_a_corrected_total_marked_as_hand_entered(): void
    {
        Storage::fake('local');
        [$user, $owner] = $this->employee();
        $claim = $this->draftClaim($owner);
        $category = $this->category();

        $this->actingAs($user)->postJson(route('user.claims.inline-add-item', $claim), [
            'expense_category_id' => $category->id,
            'description' => 'Fruits',
            'expense_date' => '2026-06-19',
            'c_total' => '19.65',
            'c_total_manual' => '1',
            'amount' => 19.65,
            'receipt' => UploadedFile::fake()->image('receipt.png', 40, 40),
        ])->assertStatus(200);

        $item = $claim->fresh('items')->items()->where('description', 'Fruits')->first();

        $this->actingAs($user)->postJson(route('user.claims.inline-update-item', $item), [
            'expense_category_id' => $category->id,
            'description' => 'Fruits for the office',
            'expense_date' => '2026-06-19',
            'c_total' => '19.65',
            'c_total_manual' => '1',
            'amount' => 19.65,
            'receipt' => UploadedFile::fake()->image('receipt-again.png', 40, 40),
        ])->assertStatus(200);

        $this->assertSame('manual', $item->fresh()->ocr_details['total_source'] ?? null);
    }

    /** ...and an edit that does NOT re-assert the mark must not keep claiming a person typed it. */
    public function test_a_rescan_that_reads_the_total_itself_drops_the_hand_entered_mark(): void
    {
        Storage::fake('local');
        [$user, $owner] = $this->employee();
        $claim = $this->draftClaim($owner);
        $category = $this->category();

        $this->actingAs($user)->postJson(route('user.claims.inline-add-item', $claim), [
            'expense_category_id' => $category->id,
            'description' => 'Fruits',
            'expense_date' => '2026-06-19',
            'c_total' => '19.65',
            'c_total_manual' => '1',
            'amount' => 19.65,
            'receipt' => UploadedFile::fake()->image('receipt.png', 40, 40),
        ])->assertStatus(200);

        $item = $claim->fresh('items')->items()->where('description', 'Fruits')->first();

        $this->actingAs($user)->postJson(route('user.claims.inline-update-item', $item), [
            'expense_category_id' => $category->id,
            'description' => 'Fruits, rescanned',
            'expense_date' => '2026-06-19',
            'c_total' => '19.65',   // the new scan read it correctly this time
            'amount' => 19.65,
            'receipt' => UploadedFile::fake()->image('clearer.png', 40, 40),
        ])->assertStatus(200);

        $this->assertArrayNotHasKey('total_source', $item->fresh()->ocr_details ?? []);
    }
}
