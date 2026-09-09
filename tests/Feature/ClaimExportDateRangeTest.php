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
use Illuminate\Support\Facades\Storage;
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

    // ── The archive itself is scoped, not just the request ────────────────

    /** Every PDF actually inside a rendered export part, by entry name. */
    private function entriesOf(ExpenseClaimZipExport $export): array
    {
        $names = [];

        foreach ($export->partList() as $part) {
            $tmp = tempnam(sys_get_temp_dir(), 'zip-scope-');
            file_put_contents($tmp, Storage::disk('local')->get($part['path']));

            $zip = new \ZipArchive;
            $this->assertTrue($zip->open($tmp) === true, "Part {$part['path']} is not a readable ZIP archive.");
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $names[] = $zip->getNameIndex($i);
            }
            $zip->close();
            @unlink($tmp);
        }

        return $names;
    }

    /**
     * Does the finished archive hold a PDF for this claim?
     *
     * Matched on the CLAIMANT'S NAME, because that is what pdfFilename() actually builds the
     * entry name from — the claim number never appears in it. Each fixture below gives its
     * claimant a distinct name for exactly this reason.
     */
    private function archiveCovers(array $entries, ExpenseClaim $claim): bool
    {
        $name = $claim->employee->full_name;

        return (bool) array_filter($entries, fn ($n) => str_contains($n, $name));
    }

    /**
     * The end-to-end guarantee the operator actually cares about: what lands in the ZIP is
     * bounded by the window, not merely what the request row says it asked for.
     *
     * Every other window test here stops at the request or asks the service directly. This one
     * runs the real job and opens the real archive, because the reported failure was precisely
     * a disagreement between the period on screen and the claims in the file — 1–8 Sep coming
     * back as 96 claims. A test that never opens the ZIP cannot see that.
     */
    public function test_the_rendered_archive_holds_only_the_claims_approved_inside_the_window(): void
    {
        $cat = $this->category();
        $firstDay = $this->approvedAt($cat, 'First Day', '2026-09-01 00:05:00');
        $middle = $this->approvedAt($cat, 'Middle', '2026-09-04 13:00:00');
        $lastDay = $this->approvedAt($cat, 'Last Day', '2026-09-08 23:50:00');
        $dayBefore = $this->approvedAt($cat, 'Day Before', '2026-08-31 23:50:00');
        $dayAfter = $this->approvedAt($cat, 'Day After', '2026-09-09 00:05:00');

        $response = $this->actingAs($this->hrUser())
            ->postJson(route('hr.claims.download-zip'), ['year' => 2026, 'from' => '2026-09-01', 'to' => '2026-09-08']);
        $response->assertOk()->assertJson(['ok' => true, 'total_matched' => 3]);

        $export = ExpenseClaimZipExport::findOrFail($response->json('export_id'));
        (new BuildClaimZipExport($export->id))->handle(new ClaimZipExportService);
        $export->refresh();

        $this->assertSame(ExpenseClaimZipExport::STATUS_READY, $export->status);
        $this->assertSame(3, (int) $export->rendered_count);

        $entries = $this->entriesOf($export);
        $this->assertTrue($this->archiveCovers($entries, $firstDay), 'the first day of the window must be included in full');
        $this->assertTrue($this->archiveCovers($entries, $middle));
        $this->assertTrue($this->archiveCovers($entries, $lastDay), 'the last day of the window must be included in full');
        $this->assertFalse($this->archiveCovers($entries, $dayBefore), 'a claim approved before the window must not be in the archive');
        $this->assertFalse($this->archiveCovers($entries, $dayAfter), 'a claim approved after the window must not be in the archive');

        // The posted year must not widen the window it was sent alongside — the modal always
        // posts its hidden year, so a range that fell back to the cycle would silently return
        // the whole year and look like a working export.
        $this->assertNull($export->year);
        $this->assertCount(3, array_filter($entries, fn ($n) => str_ends_with($n, '.pdf')));
    }

    /**
     * The exact shape the BROWSER posts — form-urlencoded, with the hidden year riding
     * alongside the dates and the company as a `company[]` array — rather than the JSON every
     * other test here sends.
     *
     * The modal submits `new URLSearchParams(new FormData(form))`, which is urlencoded, not
     * JSON. Until now nothing proved that shape parses and scopes on the way in: the field
     * that broke was dropped in the browser, so production had never once delivered a
     * populated body through this endpoint, and a suite that only ever posts JSON cannot tell
     * "the server reads this correctly" from "the server never sees it".
     */
    public function test_the_browsers_own_form_encoded_shape_scopes_to_the_window_and_the_company(): void
    {
        Queue::fake();
        $cat = $this->category();
        $wanted = $this->approvedAt($cat, 'Enlinea In', '2026-09-04 10:00:00', 100.0, 'Enlinea Sdn. Bhd.');
        $this->approvedAt($cat, 'Other Company In', '2026-09-04 10:00:00', 100.0, 'Claritas Asia Sdn. Bhd.');
        $this->approvedAt($cat, 'Enlinea Out', '2026-09-20 10:00:00', 100.0, 'Enlinea Sdn. Bhd.');

        // ->post() sends application/x-www-form-urlencoded, exactly as the modal's fetch does.
        $response = $this->actingAs($this->hrUser())->post(route('hr.claims.download-zip'), [
            'year' => 2026,
            'from' => '2026-09-01',
            'to' => '2026-09-08',
            'company' => ['Enlinea Sdn. Bhd.'],
        ]);

        $response->assertOk()->assertJson(['ok' => true, 'total_matched' => 1]);

        $export = ExpenseClaimZipExport::findOrFail($response->json('export_id'));
        $this->assertSame(['Enlinea Sdn. Bhd.'], $export->companies, 'the company[] array must survive urlencoding');
        $this->assertSame('2026-09-01', $export->from_date->toDateString());
        $this->assertSame('2026-09-08', $export->to_date->toDateString());
        $this->assertNull($export->year, 'the window must win over the hidden year posted beside it');

        $matched = app(ClaimZipExportService::class)
            ->claimsApprovedBetween($export->from_date, $export->to_date, $export->companies)
            ->pluck('claim_number')->all();
        $this->assertSame([$wanted->claim_number], $matched);
    }

    /**
     * The mirror: with no window the cycle still bounds the archive. Both selectable periods
     * scope the file, so neither path can quietly become "everything".
     */
    public function test_the_rendered_archive_holds_only_the_selected_cycle_when_no_window_is_given(): void
    {
        $cat = $this->category();
        // The August cycle runs 21 Jul – 20 Aug, so these straddle both of its edges.
        $inCycle = $this->approvedAt($cat, 'In Cycle', '2026-08-05 09:00:00');
        $beforeCycle = $this->approvedAt($cat, 'Before Cycle', '2026-07-20 09:00:00');
        $afterCycle = $this->approvedAt($cat, 'After Cycle', '2026-08-25 09:00:00');

        $response = $this->actingAs($this->hrUser())
            ->postJson(route('hr.claims.download-zip'), ['year' => 2026, 'month' => 8]);
        $response->assertOk()->assertJson(['ok' => true, 'total_matched' => 1]);

        $export = ExpenseClaimZipExport::findOrFail($response->json('export_id'));
        (new BuildClaimZipExport($export->id))->handle(new ClaimZipExportService);
        $export->refresh();

        $entries = $this->entriesOf($export->refresh());
        $this->assertTrue($this->archiveCovers($entries, $inCycle));
        $this->assertFalse($this->archiveCovers($entries, $beforeCycle), 'the cycle must not reach back into the previous one');
        $this->assertFalse($this->archiveCovers($entries, $afterCycle), 'the cycle must not reach forward into the next one');
        $this->assertCount(1, array_filter($entries, fn ($n) => str_ends_with($n, '.pdf')));
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

    /**
     * A request with NO period at all — no window and no cycle — is refused, not answered with
     * the entire history.
     *
     * This is the failure that was reported live on 2026-09-09: HR picked 1–8 Sep, ticked one
     * company, and got 96 claims. The modal built its POST body AFTER disabling the form to
     * lock it, and a disabled control is skipped by the form data set construction algorithm —
     * so the body arrived EMPTY: no dates, no company, not even the hidden year. The dates
     * dropping out was the bug; what made it a wrong export instead of a visible error was
     * this endpoint, because matchingClaims() applies no date bound whatsoever when $year is
     * null (cycleFetchRange returns [null, null] and the per-claim cycle filter short-circuits
     * on `! $year`). "No period" therefore meant "every approved claim ever", which is
     * indistinguishable from a correct export until somebody reconciles the totals.
     *
     * The two claims below sit years apart on purpose: without the guard this request answers
     * `total_matched => 2` and queues a job to render both.
     */
    public function test_a_request_naming_no_period_at_all_is_refused_rather_than_exporting_everything(): void
    {
        Queue::fake();
        $cat = $this->category();
        $this->approvedAt($cat, 'Long Ago', '2024-02-11 09:00:00');
        $this->approvedAt($cat, 'Recently', '2026-09-05 09:00:00');

        $this->actingAs($this->hrUser())
            ->post(route('hr.claims.download-zip'), [])
            ->assertStatus(422)
            ->assertJson(['ok' => false, 'error' => 'The export form sent no period at all — this usually means your page is out of date. Please reload the page and try again.']);

        Queue::assertNothingPushed();
        $this->assertSame(0, ExpenseClaimZipExport::count(), 'a periodless request must not leave an export row behind');
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

    /**
     * The dates are the ONLY period control on the export modal (2026-09-09).
     *
     * A "Quick pick" cycle dropdown sat below them and only ever FILLED them, but operators
     * read the two controls as a pair and set both, then could not say which the download had
     * obeyed. The hidden `year` went with it: with no cycle control left it could only act as a
     * SILENT fallback, and a request whose dates went missing would have exported the whole
     * year instead of saying so — the very failure this modal was just fixed for.
     *
     * The year assertion is sliced to the export form on purpose: the page carries its own
     * year FILTER, so asserting on the whole response would pass on that instead.
     */
    public function test_the_export_modal_carries_no_cycle_control_beside_the_dates(): void
    {
        $cat = $this->category();
        $this->approvedAt($cat, 'Someone', '2026-08-15 09:00:00');

        $html = $this->actingAs($this->hrUser())
            ->get(route('hr.claims.index', ['year' => 2026]))
            ->assertOk()
            ->assertDontSee('id="exportZipPreset"', false)
            ->getContent();

        $start = strpos($html, 'id="exportZipForm"');
        $this->assertNotFalse($start, 'guard: the export form must be on the page for this to mean anything');
        $form = substr($html, $start, strpos($html, '</form>', $start) - $start);

        $this->assertStringContainsString('name="from"', $form);
        $this->assertStringContainsString('name="to"', $form);
        $this->assertStringNotContainsString('name="year"', $form, 'the export form must carry no silent cycle fallback');
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
