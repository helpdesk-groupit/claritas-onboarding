<?php

namespace Tests\Feature;

use App\Http\Controllers\ExpenseClaimController;
use App\Jobs\BuildClaimZipExport;
use App\Models\Employee;
use App\Models\ExpenseCategory;
use App\Models\ExpenseClaim;
use App\Models\ExpenseClaimZipExport;
use App\Models\User;
use App\Services\ClaimZipExportService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The free approval-date window on HR's ZIP export and Finance's CSV (added 2026-09-07).
 *
 * Both downloads could only ever be asked for a cutoff cycle — the 21st of one month to the
 * 20th of the next. That is the right default for the monthly pack and it stays the default,
 * but it was also the ONLY period expressible, so an audit window, a quarter, a financial year,
 * or "everything approved since we last ran this" simply could not be exported. Both now accept
 * an explicit `from`/`to` pair, filtering on the date a claim was fully approved by BOTH Manager
 * and HR (`processed_at`), and both read the one ClaimZipExportService::claimsApprovedBetween()
 * so a ZIP and a CSV pulled for the same two dates still contain exactly the same claims — the
 * reconciliation ClaimFinanceReportTallyTest pins for cycles, held for windows too.
 */
class ClaimExportDateRangeTest extends TestCase
{
    use RefreshDatabase;

    private function hrUser(): User
    {
        $user = User::factory()->hrManager()->withTwoFactor()->create();
        Employee::factory()->withUser($user)->create();

        return $user;
    }

    private function financeUser(): User
    {
        $user = User::factory()->financeManager()->withTwoFactor()->create();
        Employee::factory()->withUser($user)->create();

        return $user;
    }

    private function category(): ExpenseCategory
    {
        return ExpenseCategory::create([
            'name' => 'Medical Fees', 'code' => 'C-'.uniqid(), 'gl_code' => '932-000',
            'rate_type' => 'receipt', 'is_active' => true,
        ]);
    }

    /** A fully-approved claim, approved at a precise moment — the axis the window filters on. */
    private function approvedAt(ExpenseCategory $cat, string $name, string $approvedAt, float $amount = 100.0, string $company = 'Enlinea Sdn. Bhd.'): ExpenseClaim
    {
        $owner = Employee::factory()->create(['company' => $company, 'full_name' => $name]);

        $claim = ExpenseClaim::create([
            'employee_id' => $owner->id,
            'year' => (int) Carbon::parse($approvedAt)->year,
            'month' => (int) Carbon::parse($approvedAt)->month,
            'company' => $company,
            'claim_number' => 'EC-RANGE-'.random_int(100000, 999999),
            'title' => 'x',
            'event' => 'Test event',
            'status' => 'hr_approved',
            'submitted_at' => $approvedAt,
            'processed_at' => $approvedAt,
        ]);

        $claim->items()->create([
            'expense_category_id' => $cat->id,
            'expense_date' => Carbon::parse($approvedAt)->toDateString(),
            'description' => 'Consultation',
            'amount' => $amount,
            'total_with_gst' => $amount,
        ]);

        $claim->recalculateTotals();

        return $claim->refresh();
    }

    private function csvBody(array $query): string
    {
        $response = $this->actingAs($this->financeUser())->get(route('finance.claim-reports.export', $query));
        $response->assertOk();

        return $response->streamedContent();
    }

    // ── The window itself ─────────────────────────────────────────────────

    /**
     * The single most likely way this feature could under-report and still look plausible.
     *
     * `processed_at` is a DATETIME, so a naive `<= '2026-08-31'` compares against
     * 2026-08-31 00:00:00 and drops everything approved during the final day of the window —
     * a whole day of approvals missing from a file that otherwise looks completely normal.
     */
    public function test_the_end_date_is_inclusive_of_the_whole_day(): void
    {
        $cat = $this->category();
        $lastMinute = $this->approvedAt($cat, 'Late In The Day', '2026-08-31 23:59:00');
        $firstMinute = $this->approvedAt($cat, 'First Thing', '2026-07-27 00:00:01');
        $justAfter = $this->approvedAt($cat, 'Next Day', '2026-09-01 00:00:01');
        $justBefore = $this->approvedAt($cat, 'Day Before', '2026-07-26 23:59:00');

        $matched = app(ClaimZipExportService::class)
            ->claimsApprovedBetween(Carbon::parse('2026-07-27'), Carbon::parse('2026-08-31'))
            ->pluck('claim_number')->all();

        $this->assertContains($lastMinute->claim_number, $matched, 'a claim approved late on the END date must be included');
        $this->assertContains($firstMinute->claim_number, $matched, 'a claim approved at the start of the FROM date must be included');
        $this->assertNotContains($justAfter->claim_number, $matched);
        $this->assertNotContains($justBefore->claim_number, $matched);
    }

    /** A window may cross a cutoff boundary, a month, and a year — that is the whole point. */
    public function test_a_window_can_span_cycles_months_and_a_year_end(): void
    {
        $cat = $this->category();
        $dec = $this->approvedAt($cat, 'December', '2026-12-28 09:00:00');
        $jan = $this->approvedAt($cat, 'January', '2027-01-05 09:00:00');
        $outside = $this->approvedAt($cat, 'February', '2027-02-05 09:00:00');

        $matched = app(ClaimZipExportService::class)
            ->claimsApprovedBetween(Carbon::parse('2026-12-01'), Carbon::parse('2027-01-31'))
            ->pluck('claim_number')->all();

        $this->assertContains($dec->claim_number, $matched);
        $this->assertContains($jan->claim_number, $matched);
        $this->assertNotContains($outside->claim_number, $matched);
    }

    /** Only claims signed off by BOTH Manager and HR — the window never widens eligibility. */
    public function test_only_fully_approved_claims_fall_inside_a_window(): void
    {
        $cat = $this->category();
        $approved = $this->approvedAt($cat, 'Approved', '2026-08-10 09:00:00');

        $pending = $this->approvedAt($cat, 'Awaiting HR', '2026-08-11 09:00:00');
        $pending->forceFill(['status' => 'manager_approved', 'processed_at' => null])->save();

        $reversed = $this->approvedAt($cat, 'Reversed', '2026-08-12 09:00:00');
        $reversed->forceFill(['status' => 'reversed', 'processed_at' => null])->save();

        $matched = app(ClaimZipExportService::class)
            ->claimsApprovedBetween(Carbon::parse('2026-08-01'), Carbon::parse('2026-08-31'))
            ->pluck('claim_number')->all();

        $this->assertSame([$approved->claim_number], $matched);
    }

    // ── The two downloads still tally ─────────────────────────────────────

    /**
     * The guarantee the whole reconciliation rests on, extended to windows: a ZIP and a CSV
     * pulled for the same two dates describe exactly the same claims.
     */
    public function test_the_zip_and_the_csv_contain_the_same_claims_for_one_window(): void
    {
        $cat = $this->category();
        $this->approvedAt($cat, 'In A', '2026-07-27 09:00:00');
        $this->approvedAt($cat, 'In B', '2026-08-15 09:00:00');
        $this->approvedAt($cat, 'In C', '2026-08-31 18:00:00');
        $this->approvedAt($cat, 'Out Early', '2026-07-26 09:00:00');
        $this->approvedAt($cat, 'Out Late', '2026-09-01 09:00:00');

        $window = ['from' => '2026-07-27', 'to' => '2026-08-31'];

        $expected = app(ClaimZipExportService::class)
            ->claimsApprovedBetween(Carbon::parse($window['from']), Carbon::parse($window['to']))
            ->pluck('claim_number')->sort()->values()->all();
        $this->assertCount(3, $expected, 'guard: the fixture must straddle both edges of the window');

        $csv = $this->csvBody($window);
        $inCsv = ExpenseClaim::pluck('claim_number')
            ->filter(fn ($n) => str_contains($csv, $n))->sort()->values()->all();

        $this->assertSame($expected, $inCsv);
    }

    public function test_the_zip_request_stores_the_window_and_the_job_renders_it(): void
    {
        Queue::fake();
        $cat = $this->category();
        $inside = $this->approvedAt($cat, 'Inside', '2026-08-15 09:00:00');
        $outside = $this->approvedAt($cat, 'Outside', '2026-09-15 09:00:00');

        $this->actingAs($this->hrUser())
            ->post(route('hr.claims.download-zip'), ['from' => '2026-07-27', 'to' => '2026-08-31'])
            ->assertOk()->assertJson(['ok' => true, 'total_matched' => 1]);

        $export = ExpenseClaimZipExport::latest('id')->first();
        $this->assertTrue($export->hasDateRange());
        $this->assertSame('2026-07-27', $export->from_date->toDateString());
        $this->assertSame('2026-08-31', $export->to_date->toDateString());

        // A request carries EITHER a window or a cycle — never both, or the row would describe
        // two different periods and the job would have to guess.
        $this->assertNull($export->year);
        $this->assertNull($export->month);

        $matched = app(ClaimZipExportService::class)
            ->claimsApprovedBetween($export->from_date, $export->to_date)
            ->pluck('claim_number')->all();
        $this->assertSame([$inside->claim_number], $matched);
        $this->assertNotContains($outside->claim_number, $matched);

        Queue::assertPushed(BuildClaimZipExport::class);
    }

    /** The cycle path is untouched — the monthly pack still works with no dates supplied. */
    public function test_a_request_with_no_dates_still_exports_by_cutoff_cycle(): void
    {
        Queue::fake();
        $cat = $this->category();
        $this->approvedAt($cat, 'August Cycle', '2026-08-15 09:00:00');

        $this->actingAs($this->hrUser())
            ->post(route('hr.claims.download-zip'), ['year' => 2026, 'month' => 8])
            ->assertOk()->assertJson(['ok' => true]);

        $export = ExpenseClaimZipExport::latest('id')->first();
        $this->assertFalse($export->hasDateRange());
        $this->assertSame(2026, (int) $export->year);
        $this->assertSame(8, (int) $export->month);
    }

    // ── Rejected windows are reported, never quietly re-interpreted ───────

    public function test_a_reversed_window_is_refused_rather_than_swapped(): void
    {
        Queue::fake();
        $cat = $this->category();
        $this->approvedAt($cat, 'Anyone', '2026-08-15 09:00:00');

        $this->actingAs($this->hrUser())
            ->post(route('hr.claims.download-zip'), ['from' => '2026-08-31', 'to' => '2026-07-27'])
            ->assertStatus(422)
            ->assertJson(['ok' => false, 'error' => 'The end date cannot be before the start date.']);

        Queue::assertNothingPushed();
        $this->assertSame(0, ExpenseClaimZipExport::count(), 'a refused request must not leave an export row behind');
    }

    public function test_half_a_window_is_refused_rather_than_completed_for_the_operator(): void
    {
        Queue::fake();
        $cat = $this->category();
        $this->approvedAt($cat, 'Anyone', '2026-08-15 09:00:00');

        $this->actingAs($this->hrUser())
            ->post(route('hr.claims.download-zip'), ['from' => '2026-08-01'])
            ->assertStatus(422)
            ->assertJson(['ok' => false, 'error' => 'Pick both a start date and an end date for the period.']);

        Queue::assertNothingPushed();
    }

    /**
     * createFromFormat accepts an impossible date that still MATCHES the format — "2026-13-45"
     * parses and rolls forward into 2027. Silently exporting that window would produce a
     * correct-looking file for a period nobody asked for.
     */
    public function test_an_impossible_date_is_refused_rather_than_rolled_over(): void
    {
        Queue::fake();
        $cat = $this->category();
        $this->approvedAt($cat, 'Anyone', '2026-08-15 09:00:00');

        $this->actingAs($this->hrUser())
            ->post(route('hr.claims.download-zip'), ['from' => '2026-13-45', 'to' => '2026-12-31'])
            ->assertStatus(422)
            ->assertJson(['ok' => false]);

        Queue::assertNothingPushed();
    }

    public function test_a_window_matching_nothing_is_reported_with_its_dates(): void
    {
        Queue::fake();
        $cat = $this->category();
        $this->approvedAt($cat, 'Elsewhere', '2026-08-15 09:00:00');

        $this->actingAs($this->hrUser())
            ->post(route('hr.claims.download-zip'), ['from' => '2026-01-01', 'to' => '2026-01-31'])
            ->assertStatus(422)
            ->assertJsonFragment(['ok' => false]);

        Queue::assertNothingPushed();
    }

    /** The CSV must not stream a file for a period the operator did not ask for. */
    public function test_the_csv_refuses_a_reversed_window_instead_of_exporting_another_period(): void
    {
        $cat = $this->category();
        $this->approvedAt($cat, 'Anyone', '2026-08-15 09:00:00');

        $this->actingAs($this->financeUser())
            ->get(route('finance.claim-reports.export', ['from' => '2026-08-31', 'to' => '2026-07-27']))
            ->assertRedirect()
            ->assertSessionHas('error', 'The end date cannot be before the start date.');
    }

    /** ...and the page shows the reason with no figures, rather than another period's totals. */
    public function test_the_report_page_shows_the_reason_and_no_rows_for_a_bad_window(): void
    {
        $cat = $this->category();
        $claim = $this->approvedAt($cat, 'Would Otherwise Show', '2026-08-15 09:00:00');

        $this->actingAs($this->financeUser())
            ->get(route('finance.claim-reports', ['from' => '2026-08-31', 'to' => '2026-07-27']))
            ->assertOk()
            ->assertSee('The end date cannot be before the start date.')
            ->assertDontSee($claim->employee->full_name);
    }

    // ── The surfaces that collect the dates ───────────────────────────────

    public function test_the_zip_modal_offers_start_and_end_date_pickers(): void
    {
        $cat = $this->category();
        $this->approvedAt($cat, 'Someone', '2026-08-15 09:00:00');

        $this->actingAs($this->hrUser())
            ->get(route('hr.claims.index', ['year' => 2026]))
            ->assertOk()
            ->assertSee('id="exportZipFrom"', false)
            ->assertSee('id="exportZipTo"', false)
            ->assertSee('Period covered');
    }

    public function test_the_finance_csv_button_opens_a_period_picker(): void
    {
        $cat = $this->category();
        $this->approvedAt($cat, 'Someone', '2026-08-15 09:00:00');

        $this->actingAs($this->financeUser())
            ->get(route('finance.claim-reports', ['year' => 2026]))
            ->assertOk()
            // A button that opens the picker, not a bare link that downloads immediately.
            ->assertSee('data-bs-target="#exportCsvModal"', false)
            ->assertSee('id="exportCsvFrom"', false)
            ->assertSee('id="exportCsvTo"', false);
    }

    /** The page says which window it is showing, so a figure can be traced to its period. */
    public function test_the_report_page_names_the_window_it_is_showing(): void
    {
        $cat = $this->category();
        $this->approvedAt($cat, 'Someone', '2026-08-15 09:00:00');

        $this->actingAs($this->financeUser())
            ->get(route('finance.claim-reports', ['from' => '2026-07-27', 'to' => '2026-08-31']))
            ->assertOk()
            ->assertSee('Approved between')
            ->assertSee('27 Jul 2026 – 31 Aug 2026', false);
    }

    /** The window overrides the cycle basis — the most specific period asked for wins. */
    public function test_a_window_overrides_the_expense_month_basis(): void
    {
        $cat = $this->category();
        $inside = $this->approvedAt($cat, 'Inside Window', '2026-08-15 09:00:00');
        // Stamped to a different reporting month, so the expense-month basis would answer
        // differently if it were still in charge.
        $inside->forceFill(['year' => 2026, 'month' => 3])->save();

        $csv = $this->csvBody([
            'from' => '2026-08-01', 'to' => '2026-08-31',
            'basis' => ExpenseClaimController::REPORT_BASIS_EXPENSE_MONTH,
        ]);

        $this->assertStringContainsString($inside->claim_number, $csv);
        $this->assertStringContainsString('Cycle Month', $csv, 'a window still labels rows by approval cycle');
    }
}
