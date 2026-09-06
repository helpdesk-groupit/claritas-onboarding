<?php

namespace Tests\Feature;

use App\Http\Controllers\ExpenseClaimController;
use App\Models\Employee;
use App\Models\ExpenseCategory;
use App\Models\ExpenseClaim;
use App\Models\User;
use App\Services\ClaimZipExportService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Finance's claim-report CSV must describe the SAME claims, in the SAME periods, as HR's
 * "Export approved PDFs (ZIP)" — otherwise the two downloads cannot be reconciled.
 *
 * They could not be, until 2026-09-05. The ZIP buckets a claim by its cutoff cycle (21st of the
 * previous month to the 20th of this one, per ClaimRulesService::submissionCycle), while the
 * finance report filtered on expense_claims.year/month — the reporting-month stamp the employee
 * picks in the New Claim modal, which is the month the SPENDING belongs to. The two are different
 * axes, so they disagreed structurally rather than at the edges: measured against production data
 * that day, 34 of 91 approved claims (37%) fell into a different bucket depending on which button
 * was pressed, and the July 2026 pull returned RM 3,092.94 of claims as a ZIP against RM
 * 11,035.18 as a CSV.
 *
 * The finance report now defaults to the cutoff cycle and takes its claim set straight from
 * ClaimZipExportService — the same engine the ZIP job runs on — so the two cannot drift again.
 * The old expense-month view survives behind ?basis=expense_month for anyone posting by expense
 * period, and says on screen that it does not tally.
 *
 * Which DATE decides the cutoff cycle changed again the very next day (2026-09-06): it is now the
 * date a claim was FULLY APPROVED (processed_at, stamped when HR approves), not the date it was
 * submitted. A claim submitted in one cycle is routinely approved in a later one — a correction,
 * a rejection/resubmission loop, a manager on leave — and both HR's and Finance's monthly pack are
 * meant to reflect what actually landed as approved spend in a given 21st–20th window, not when
 * the employee first typed the claim in. The reconciliation this suite pins (CSV agrees with ZIP)
 * is untouched by that change — both still read the one shared ClaimZipExportService::claimCycle()
 * — only the axis they both agree on moved. Fixtures below therefore place their scenario's month
 * on the APPROVAL date; submission date defaults to the same moment (most scenarios here are not
 * testing the gap between the two) except where a test explicitly proves the cycle ignores it.
 */
class ClaimFinanceReportTallyTest extends TestCase
{
    use RefreshDatabase;

    private function financeUser(): User
    {
        // finance_manager is a mandatory-2FA role, so without withTwoFactor() every request
        // here is bounced by EnforceTwoFactor and each assertion fails as a bare 302.
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

    /**
     * A fully-approved claim, stamped with a reporting month and approved (processed_at) on a
     * given date — the field that now decides which cutoff cycle it lands in. `submitted_at`
     * defaults to the SAME moment as approval, since most fixtures below aren't testing the gap
     * between the two; the one test that is (test_the_csv_and_the_zip_agree_on_which_cycle_a_
     * claim_belongs_to, and its HR-CSV mirror) passes a different $submittedAt explicitly to
     * prove the cycle ignores it.
     */
    private function approvedClaim(
        ExpenseCategory $cat,
        string $name,
        int $stampMonth,
        string $approvedAt,
        float $amount = 100.0,
        string $company = 'Enlinea Sdn. Bhd.',
        ?string $submittedAt = null
    ): ExpenseClaim {
        $owner = Employee::factory()->create(['company' => $company, 'full_name' => $name]);

        $claim = ExpenseClaim::create([
            'employee_id' => $owner->id,
            'year' => 2026,
            'month' => $stampMonth,
            'company' => $company,
            'claim_number' => 'EC-2026-'.str_pad((string) $stampMonth, 2, '0', STR_PAD_LEFT).'-'.random_int(1000, 9999),
            'title' => 'x',
            'event' => 'Test event',
            'status' => 'hr_approved',
            'submitted_at' => $submittedAt ?? $approvedAt,
            // Stamped at HR approval; the ZIP export's gate AND (since 2026-09-06) the cycle's
            // reference date — not submission.
            'processed_at' => $approvedAt,
        ]);

        $claim->items()->create([
            'expense_category_id' => $cat->id,
            'expense_date' => sprintf('2026-%02d-03', $stampMonth),
            'description' => 'Consultation',
            'amount' => $amount,
            'total_with_gst' => $amount,
        ]);

        // The claim-level totals are derived, exactly as every real write path does it — without
        // this the claim's own total_with_gst stays 0 and a total comparison passes on nothing.
        $claim->recalculateTotals();

        return $claim->refresh();
    }

    private function csvBody(User $user, array $query): string
    {
        $response = $this->actingAs($user)->get(route('finance.claim-reports.export', $query));
        $response->assertOk();

        return $response->streamedContent();
    }

    // ── The tally itself ──────────────────────────────────────────────────

    /**
     * The regression this suite exists for, updated for the approval-dated cycle: a claim
     * submitted in July but only approved in August is archived in the August ZIP, so it must be
     * reported in the August CSV — not July's, whatever its submission date says.
     */
    public function test_the_csv_and_the_zip_agree_on_which_cycle_a_claim_belongs_to(): void
    {
        $user = $this->financeUser();
        $cat = $this->category();

        // Submitted in July, approved in August (e.g. after a correction loop) — the ordinary
        // case, and the one that used to split before the cycle followed approval.
        $late = $this->approvedClaim($cat, 'Late Approval', 7, '2026-08-05 09:00:00', 250.0, submittedAt: '2026-07-18 09:00:00');
        // Submitted AND approved inside July's own cycle.
        $onTime = $this->approvedClaim($cat, 'Prompt Approval', 7, '2026-07-15 09:00:00', 90.0);

        $zip = app(ClaimZipExportService::class);
        $this->assertSame(8, $zip->claimCycle($late)['month'], 'guard: the fixture must actually straddle two cycles');
        $this->assertSame(7, $zip->claimCycle($onTime)['month'], 'guard: the on-time claim must sit in July');

        $july = $this->csvBody($user, ['year' => 2026, 'month' => 7]);
        $this->assertStringContainsString($onTime->claim_number, $july);
        $this->assertStringNotContainsString($late->claim_number, $july, 'a claim archived in the August ZIP must not be reported under July');

        $august = $this->csvBody($user, ['year' => 2026, 'month' => 8]);
        $this->assertStringContainsString($late->claim_number, $august);
        $this->assertStringNotContainsString($onTime->claim_number, $august);
    }

    /**
     * The strongest form of the guarantee: for every cycle, the CSV's claim set is exactly the
     * ZIP's. Asserted against the export engine itself rather than a hand-written expectation,
     * so it keeps holding if the cycle rule ever changes.
     */
    public function test_every_cycle_reports_exactly_the_claims_the_zip_would_archive(): void
    {
        $user = $this->financeUser();
        $cat = $this->category();

        $this->approvedClaim($cat, 'A', 6, '2026-06-19 09:00:00');   // approved within June cycle
        $this->approvedClaim($cat, 'B', 6, '2026-06-25 09:00:00');   // approved after the cutoff, rolls to July
        $this->approvedClaim($cat, 'C', 7, '2026-08-05 09:00:00');   // approved in August
        $this->approvedClaim($cat, 'D', 8, '2026-08-20 09:00:00');   // approved on the cutoff day itself — still August
        $this->approvedClaim($cat, 'E', 8, '2026-08-21 09:00:00');   // approved the day after cutoff, rolls to September

        $zip = app(ClaimZipExportService::class);

        foreach ([6, 7, 8, 9] as $month) {
            $expected = $zip->matchingClaims(2026, $month)->pluck('claim_number')->sort()->values()->all();

            $csv = $this->csvBody($user, ['year' => 2026, 'month' => $month]);
            $actual = collect(ExpenseClaim::pluck('claim_number'))
                ->filter(fn ($n) => str_contains($csv, $n))->sort()->values()->all();

            $this->assertSame($expected, $actual, "cycle 2026-{$month} does not match the ZIP's contents");
        }
    }

    public function test_the_totals_of_the_two_downloads_match_for_a_cycle(): void
    {
        $user = $this->financeUser();
        $cat = $this->category();

        $this->approvedClaim($cat, 'A', 7, '2026-08-05 09:00:00', 250.0);
        $this->approvedClaim($cat, 'B', 8, '2026-08-11 09:00:00', 40.5);
        $this->approvedClaim($cat, 'C', 8, '2026-08-25 09:00:00', 999.0); // approved in September — must not count

        $zipTotal = app(ClaimZipExportService::class)->matchingClaims(2026, 8)->sum('total_with_gst');
        $this->assertEqualsWithDelta(290.5, $zipTotal, 0.001, 'guard: the fixture must exclude the September claim');

        $csv = $this->csvBody($user, ['year' => 2026, 'month' => 8]);
        $csvTotal = 0.0;
        foreach (array_slice(array_filter(explode("\n", $csv)), 1) as $line) {
            $cols = str_getcsv(trim($line));
            $csvTotal += (float) str_replace(',', '', $cols[7] ?? '0');
        }

        $this->assertEqualsWithDelta($zipTotal, $csvTotal, 0.001);
    }

    // ── The basis switch ──────────────────────────────────────────────────

    public function test_the_cutoff_cycle_is_the_default_basis(): void
    {
        $user = $this->financeUser();
        $cat = $this->category();
        $late = $this->approvedClaim($cat, 'Late Approval', 7, '2026-08-05 09:00:00');

        // No ?basis= at all: an operator who changes nothing must get the reconcilable view.
        $this->assertStringNotContainsString($late->claim_number, $this->csvBody($user, ['year' => 2026, 'month' => 7]));
        $this->assertStringContainsString($late->claim_number, $this->csvBody($user, ['year' => 2026, 'month' => 8]));
    }

    public function test_the_expense_month_basis_still_reports_by_the_reporting_stamp(): void
    {
        $user = $this->financeUser();
        $cat = $this->category();
        $late = $this->approvedClaim($cat, 'Late Approval', 7, '2026-08-05 09:00:00');

        $csv = $this->csvBody($user, [
            'year' => 2026, 'month' => 7,
            'basis' => ExpenseClaimController::REPORT_BASIS_EXPENSE_MONTH,
        ]);

        $this->assertStringContainsString($late->claim_number, $csv, 'the expense-month view must keep its old answer');
        $this->assertStringContainsString('Expense Month', $csv, 'the columns must name the axis they were built on');
    }

    /**
     * The two bases put a claim in different buckets by design, so each download has to say
     * which one it was built on. "Month" alone leaves a reconciled figure unprovable.
     */
    public function test_each_download_names_the_basis_it_was_built_on(): void
    {
        $user = $this->financeUser();
        $cat = $this->category();
        $this->approvedClaim($cat, 'A', 8, '2026-08-11 09:00:00');

        $this->assertStringContainsString('Cycle Month', $this->csvBody($user, ['year' => 2026]));
        $this->assertStringContainsString(
            'Expense Month',
            $this->csvBody($user, ['year' => 2026, 'basis' => ExpenseClaimController::REPORT_BASIS_EXPENSE_MONTH])
        );
    }

    /** Reconciling a CSV row against a PDF in the ZIP needs a key both carry. */
    public function test_the_csv_carries_the_claim_number_that_names_the_pdf_in_the_zip(): void
    {
        $user = $this->financeUser();
        $cat = $this->category();
        $claim = $this->approvedClaim($cat, 'A', 8, '2026-08-11 09:00:00');

        $csv = $this->csvBody($user, ['year' => 2026, 'month' => 8]);

        $this->assertStringContainsString('Claim Number', $csv);
        $this->assertStringContainsString($claim->claim_number, $csv);
    }

    /**
     * An unknown or absent basis must fall back to the cycle, never to a third behaviour — a
     * hand-edited URL is not a reason to hand finance an unreconcilable download.
     */
    public function test_an_unrecognised_basis_falls_back_to_the_cycle(): void
    {
        $user = $this->financeUser();
        $cat = $this->category();
        $late = $this->approvedClaim($cat, 'Late Approval', 7, '2026-08-05 09:00:00');

        $csv = $this->csvBody($user, ['year' => 2026, 'month' => 7, 'basis' => 'whatever']);
        $this->assertStringNotContainsString($late->claim_number, $csv);
    }

    // ── The approval gate ─────────────────────────────────────────────────

    /**
     * A claim approved in bulk is as approved as one approved singly. bulkApprove() set the
     * status and both HR columns but never processed_at, so such a claim would have been fully
     * approved on screen and permanently absent from BOTH the ZIP and the cycle CSV — with
     * nothing anywhere reporting it missing. Nothing had reached that state in production, but
     * the route is registered and reachable.
     */
    public function test_a_bulk_approved_claim_reaches_the_zip_and_the_csv(): void
    {
        $hr = User::factory()->hrManager()->withTwoFactor()->create();
        Employee::factory()->withUser($hr)->create();

        $cat = $this->category();
        $claim = $this->approvedClaim($cat, 'Bulk Approved', 8, '2026-08-11 09:00:00');
        // Wind it back to the state bulk approval acts on.
        $claim->forceFill(['status' => 'manager_approved', 'processed_at' => null])->save();

        // bulkApprove() stamps processed_at = now(), and the cycle is decided by that stamp
        // since 2026-09-06 — so the approval has to actually land inside the August cutoff
        // cycle for this to prove anything, hence pinning the clock rather than letting the
        // real "now" (whatever day the suite happens to run on) decide the outcome.
        $this->travelTo(Carbon::parse('2026-08-11 10:00:00'), function () use ($hr, $claim) {
            $this->actingAs($hr)
                ->post(route('hr.claims.bulk-approve'), ['claim_ids' => [$claim->id]])
                ->assertRedirect();
        });

        $claim->refresh();
        $this->assertSame('hr_approved', $claim->status);
        $this->assertNotNull($claim->processed_at, 'bulk approval must stamp processed_at exactly as hrApprove() does');

        $this->assertTrue(
            app(ClaimZipExportService::class)->matchingClaims(2026, 8)->contains('id', $claim->id),
            'a bulk-approved claim must be archived by the ZIP export'
        );
        $this->assertStringContainsString($claim->claim_number, $this->csvBody($this->financeUser(), ['year' => 2026, 'month' => 8]));
    }

    /**
     * A reversed claim is no longer approved, and reverse() nulls processed_at. Both downloads
     * read that one column, so neither may keep reporting it.
     */
    public function test_a_reversed_claim_leaves_both_downloads_together(): void
    {
        $user = $this->financeUser();
        $cat = $this->category();
        $claim = $this->approvedClaim($cat, 'Reversed', 8, '2026-08-11 09:00:00');

        $this->assertStringContainsString($claim->claim_number, $this->csvBody($user, ['year' => 2026, 'month' => 8]));

        $claim->forceFill(['status' => 'reversed', 'processed_at' => null])->save();

        $this->assertFalse(app(ClaimZipExportService::class)->matchingClaims(2026, 8)->contains('id', $claim->id));
        $this->assertStringNotContainsString($claim->claim_number, $this->csvBody($user, ['year' => 2026, 'month' => 8]));
    }

    // ── Filters must not break the tally ──────────────────────────────────

    public function test_the_company_filter_narrows_both_downloads_the_same_way(): void
    {
        $user = $this->financeUser();
        $cat = $this->category();

        $mine = $this->approvedClaim($cat, 'Enlinea Staff', 8, '2026-08-11 09:00:00', 100.0, 'Enlinea Sdn. Bhd.');
        $other = $this->approvedClaim($cat, 'Claritas Staff', 8, '2026-08-11 09:00:00', 100.0, 'Claritas Asia Sdn. Bhd.');

        $csv = $this->csvBody($user, ['year' => 2026, 'month' => 8, 'company' => 'Enlinea Sdn. Bhd.']);

        $this->assertStringContainsString($mine->claim_number, $csv);
        $this->assertStringNotContainsString($other->claim_number, $csv);

        $zipIds = app(ClaimZipExportService::class)->matchingClaims(2026, 8, ['Enlinea Sdn. Bhd.'])->pluck('id')->all();
        $this->assertSame([$mine->id], $zipIds, 'the ZIP must narrow by the same company filter');
    }

    /**
     * A December claim approved after the cutoff belongs to the NEXT year's January cycle, so
     * the year list has to be derived from the cycle and not from a DISTINCT on either the
     * reporting stamp or the approval year — otherwise the year it is exported under is not
     * offered on the page that exports it.
     */
    public function test_the_year_list_offers_a_cycle_year_no_column_records(): void
    {
        $user = $this->financeUser();
        $cat = $this->category();
        $claim = $this->approvedClaim($cat, 'Year End', 12, '2026-12-28 09:00:00');

        $cycle = app(ClaimZipExportService::class)->claimCycle($claim);
        $this->assertSame([2027, 1], [$cycle['year'], $cycle['month']], 'guard: the fixture must cross the year boundary');

        $this->assertContains(2027, app(ClaimZipExportService::class)->availableCycleYears());
        $this->actingAs($user)->get(route('finance.claim-reports', ['year' => 2027, 'month' => 1]))
            ->assertOk()->assertSee($claim->employee->full_name);
    }

    /**
     * Tying the CSV to the ZIP's engine means a claim the ZIP cannot archive is a claim the CSV
     * cannot report. That is correct, but it must never be silent: a finance total that is short
     * and says nothing is worse than the divergence this whole change removes. Production has no
     * such claim; a future approval path that forgets the stamp would create one.
     */
    public function test_an_approved_claim_that_cannot_be_archived_is_named_rather_than_dropped(): void
    {
        $user = $this->financeUser();
        $cat = $this->category();
        $good = $this->approvedClaim($cat, 'Normal', 8, '2026-08-11 09:00:00');
        $stray = $this->approvedClaim($cat, 'Unstamped', 8, '2026-08-11 09:00:00');
        $stray->forceFill(['processed_at' => null])->save();

        $response = $this->actingAs($user)->get(route('finance.claim-reports', ['year' => 2026, 'month' => 8]));

        $response->assertOk()
            ->assertSee('cannot be reported by cycle')
            ->assertSee($stray->claim_number)
            ->assertSee($good->claim_number === $stray->claim_number ? 'x' : 'Normal');

        // ...and it is genuinely excluded from the figures, not merely mentioned.
        $this->assertStringNotContainsString($stray->claim_number, $this->csvBody($user, ['year' => 2026, 'month' => 8]));
    }

    public function test_a_healthy_report_shows_no_integrity_warning(): void
    {
        $user = $this->financeUser();
        $cat = $this->category();
        $this->approvedClaim($cat, 'Normal', 8, '2026-08-11 09:00:00');

        $this->actingAs($user)->get(route('finance.claim-reports', ['year' => 2026]))
            ->assertOk()->assertDontSee('cannot be reported by cycle');
    }

    // ── The HR CSV (hr.claims.export) ─────────────────────────────────────

    private function hrCsv(array $query): string
    {
        $hr = User::factory()->hrManager()->withTwoFactor()->create();
        Employee::factory()->withUser($hr)->create();

        $response = $this->actingAs($hr)->get(route('hr.claims.export', $query));
        $response->assertOk();

        return $response->streamedContent();
    }

    public function test_the_hr_csv_reports_an_approved_claim_in_the_same_cycle_as_the_zip(): void
    {
        $cat = $this->category();
        $late = $this->approvedClaim($cat, 'Late Approval', 7, '2026-08-05 09:00:00', submittedAt: '2026-07-18 09:00:00');
        $onTime = $this->approvedClaim($cat, 'Prompt Approval', 7, '2026-07-15 09:00:00');

        $july = $this->hrCsv(['year' => 2026, 'month' => 7]);
        $this->assertStringContainsString($onTime->claim_number, $july);
        $this->assertStringNotContainsString($late->claim_number, $july);

        $august = $this->hrCsv(['year' => 2026, 'month' => 8]);
        $this->assertStringContainsString($late->claim_number, $august);
        $this->assertStringNotContainsString($onTime->claim_number, $august);
    }

    /**
     * The trap this export sets. Its purpose includes claims that are NOT approved, so it must
     * never be routed through ClaimZipExportService::matchingClaims() — that gates on
     * processed_at and would silently empty most of the file while still returning a valid CSV.
     * These fixtures are un-approved AFTER creation (processed_at nulled), so their cycle falls
     * back to submitted_at — which defaults to the same month as the approval date they were
     * created with, and is left untouched by the forceFill below.
     */
    public function test_the_hr_csv_still_carries_claims_that_are_not_approved(): void
    {
        $cat = $this->category();

        $approved = $this->approvedClaim($cat, 'Approved', 8, '2026-08-11 09:00:00');

        $pending = $this->approvedClaim($cat, 'Awaiting HR', 8, '2026-08-12 09:00:00');
        $pending->forceFill(['status' => 'manager_approved', 'processed_at' => null])->save();

        $rejected = $this->approvedClaim($cat, 'Rejected', 8, '2026-08-13 09:00:00');
        $rejected->forceFill(['status' => 'hr_rejected', 'processed_at' => null])->save();

        $csv = $this->hrCsv(['year' => 2026, 'month' => 8]);

        $this->assertStringContainsString($approved->claim_number, $csv);
        $this->assertStringContainsString($pending->claim_number, $csv, 'a manager-approved claim must survive the cycle filter via its submission-date fallback');
        $this->assertStringContainsString($rejected->claim_number, $csv, 'a rejected claim must survive the cycle filter via its submission-date fallback');
    }

    public function test_the_hr_csv_still_excludes_drafts_unless_asked_for_them(): void
    {
        $cat = $this->category();
        $draft = $this->approvedClaim($cat, 'Drafter', 8, '2026-08-11 09:00:00');
        $draft->forceFill(['status' => 'draft', 'processed_at' => null])->save();

        $this->assertStringNotContainsString($draft->claim_number, $this->hrCsv(['year' => 2026, 'month' => 8]));
        $this->assertStringContainsString($draft->claim_number, $this->hrCsv(['year' => 2026, 'month' => 8, 'status' => 'draft']));
    }

    public function test_the_hr_csv_names_its_basis_and_can_still_report_by_expense_month(): void
    {
        $cat = $this->category();
        $late = $this->approvedClaim($cat, 'Late Approval', 7, '2026-08-05 09:00:00');

        $cycle = $this->hrCsv(['year' => 2026, 'month' => 8]);
        $this->assertStringContainsString('Cycle (21st-20th)', $cycle);
        $this->assertStringContainsString('2026-08', $cycle, 'the period column must carry the cycle, not the stamp');

        $stamp = $this->hrCsv([
            'year' => 2026, 'month' => 7,
            'basis' => ExpenseClaimController::REPORT_BASIS_EXPENSE_MONTH,
        ]);
        $this->assertStringContainsString('Expense Period', $stamp);
        $this->assertStringContainsString($late->claim_number, $stamp, 'the expense-month view must keep its old answer');
    }

    /** An unfiltered export must still return everything, not silently drop the whole file. */
    public function test_the_hr_csv_without_a_period_filter_returns_every_claim(): void
    {
        $cat = $this->category();
        $a = $this->approvedClaim($cat, 'A', 7, '2026-07-15 09:00:00');
        $b = $this->approvedClaim($cat, 'B', 8, '2026-08-25 09:00:00');

        $csv = $this->hrCsv([]);

        $this->assertStringContainsString($a->claim_number, $csv);
        $this->assertStringContainsString($b->claim_number, $csv);
    }

    public function test_the_page_states_which_basis_it_is_showing(): void
    {
        $user = $this->financeUser();
        $cat = $this->category();
        $this->approvedClaim($cat, 'A', 8, '2026-08-11 09:00:00');

        $this->actingAs($user)->get(route('finance.claim-reports', ['year' => 2026]))
            ->assertOk()->assertSee('Grouped by approval cycle');

        $this->actingAs($user)->get(route('finance.claim-reports', [
            'year' => 2026, 'basis' => ExpenseClaimController::REPORT_BASIS_EXPENSE_MONTH,
        ]))->assertOk()->assertSee('does not tally');
    }
}
