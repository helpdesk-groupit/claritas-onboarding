<?php

namespace Tests\Unit;

use App\Services\ClaimRulesService;
use Carbon\Carbon;
use Tests\TestCase;

class ClaimPeriodRulesTest extends TestCase
{
    public function test_item_date_must_fall_within_the_claim_month(): void
    {
        $this->assertTrue(ClaimRulesService::itemDateInPeriod('2026-04-01', 2026, 4));
        $this->assertTrue(ClaimRulesService::itemDateInPeriod('2026-04-15', 2026, 4));
        $this->assertTrue(ClaimRulesService::itemDateInPeriod('2026-04-30', 2026, 4));

        // A May receipt is not valid for an April claim, and vice-versa.
        $this->assertFalse(ClaimRulesService::itemDateInPeriod('2026-05-01', 2026, 4));
        $this->assertFalse(ClaimRulesService::itemDateInPeriod('2026-03-31', 2026, 4));
        // Right month, wrong year.
        $this->assertFalse(ClaimRulesService::itemDateInPeriod('2025-04-15', 2026, 4));
    }

    public function test_a_receipt_is_claimed_under_the_period_it_pays_for_not_the_day_it_was_paid(): void
    {
        // The Jaya One season-parking receipt: settled 30/07/2026, covers 1/08 – 31/08/2026.
        // Its payment date is in July, but the expense is August's.
        $this->assertFalse(ClaimRulesService::itemDateInPeriod('2026-07-30', 2026, 8));
        $this->assertTrue(ClaimRulesService::coverageInPeriod('2026-08-01', '2026-08-31', 2026, 8));

        // ...and it does NOT thereby become a July expense.
        $this->assertFalse(ClaimRulesService::coverageInPeriod('2026-08-01', '2026-08-31', 2026, 7));
    }

    public function test_a_coverage_period_overlapping_the_claim_month_counts(): void
    {
        // A quarterly pass genuinely is an expense of each month it covers. Claiming the same
        // document three times is stopped by the receipt-hash dedup and the cap, not here.
        $this->assertTrue(ClaimRulesService::coverageInPeriod('2026-07-01', '2026-09-30', 2026, 7));
        $this->assertTrue(ClaimRulesService::coverageInPeriod('2026-07-01', '2026-09-30', 2026, 8));
        $this->assertTrue(ClaimRulesService::coverageInPeriod('2026-07-01', '2026-09-30', 2026, 9));
        $this->assertFalse(ClaimRulesService::coverageInPeriod('2026-07-01', '2026-09-30', 2026, 10));

        // Touching the very first / very last day of the month is still an overlap.
        $this->assertTrue(ClaimRulesService::coverageInPeriod('2026-08-31', '2026-09-30', 2026, 8));
        $this->assertTrue(ClaimRulesService::coverageInPeriod('2026-07-01', '2026-08-01', 2026, 8));
    }

    public function test_a_period_that_cannot_be_believed_is_no_period_at_all(): void
    {
        // Half a range is not a range — the other end would have to be invented, and the
        // caller is about to trust it to admit a receipt from outside the month.
        $this->assertNull(ClaimRulesService::coveragePeriod('2026-08-01', null));
        $this->assertNull(ClaimRulesService::coveragePeriod(null, '2026-08-31'));
        $this->assertNull(ClaimRulesService::coveragePeriod('2026-08-01', ''));

        // Backwards, unreadable, or longer than any real season/subscription term.
        $this->assertNull(ClaimRulesService::coveragePeriod('2026-08-31', '2026-08-01'));
        $this->assertNull(ClaimRulesService::coveragePeriod('not a date', 'nor this'));
        $this->assertNull(ClaimRulesService::coveragePeriod('2020-01-01', '2030-12-31'));

        // ...and none of them admits a receipt into a month it doesn't belong to.
        $this->assertFalse(ClaimRulesService::coverageInPeriod('2026-08-01', null, 2026, 8));
        $this->assertFalse(ClaimRulesService::coverageInPeriod('2020-01-01', '2030-12-31', 2026, 8));
    }

    public function test_a_full_year_term_is_still_believed(): void
    {
        // An annual licence or insurance policy is a real document; the bound exists to reject
        // a misread decade, not to disqualify the longest legitimate term.
        $this->assertNotNull(ClaimRulesService::coveragePeriod('2026-01-01', '2026-12-31'));
        $this->assertTrue(ClaimRulesService::coverageInPeriod('2026-01-01', '2026-12-31', 2026, 8));
    }

    public function test_current_year_months_are_open_but_future_months_are_not(): void
    {
        $now = Carbon::create(2026, 6, 15);

        $this->assertTrue(ClaimRulesService::isPeriodOpenForFiling(2026, 4, $now));  // past month, this year
        $this->assertTrue(ClaimRulesService::isPeriodOpenForFiling(2026, 6, $now));  // current month
        $this->assertFalse(ClaimRulesService::isPeriodOpenForFiling(2026, 7, $now)); // future month
    }

    public function test_previous_year_is_closed_outside_the_january_grace(): void
    {
        // Mid-2026: a 2025 claim can no longer be filed.
        $this->assertFalse(ClaimRulesService::isPeriodOpenForFiling(2025, 12, Carbon::create(2026, 6, 15)));
    }

    public function test_previous_year_is_open_during_the_january_grace_window(): void
    {
        // 15 Jan 2027 (grace day = 20): any 2026 month may still be filed.
        $inGrace = Carbon::create(2027, 1, 15);
        $this->assertTrue(ClaimRulesService::isPeriodOpenForFiling(2026, 12, $inGrace));
        $this->assertTrue(ClaimRulesService::isPeriodOpenForFiling(2026, 4, $inGrace));
        $this->assertTrue(ClaimRulesService::isPeriodOpenForFiling(2027, 1, $inGrace)); // current year too

        // 21 Jan 2027: past the grace day — 2026 is closed.
        $afterGrace = Carbon::create(2027, 1, 21);
        $this->assertFalse(ClaimRulesService::isPeriodOpenForFiling(2026, 12, $afterGrace));

        // 1 Feb 2027: well past grace.
        $this->assertFalse(ClaimRulesService::isPeriodOpenForFiling(2026, 12, Carbon::create(2027, 2, 1)));
    }
}
