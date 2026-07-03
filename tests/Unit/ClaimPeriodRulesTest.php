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
