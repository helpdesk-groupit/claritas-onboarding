<?php

namespace Tests\Unit;

use App\Models\ExpenseCategory;
use App\Services\ClaimRulesService;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * Pure rules-engine logic (no DB): computed amounts, mileage rates, OT bands,
 * and working-day deadline roll-back.
 */
class ClaimRulesServiceTest extends TestCase
{
    public function test_mileage_rates_by_vehicle_with_fallback(): void
    {
        $this->assertEqualsWithDelta(0.70, ClaimRulesService::mileageRate('car'), 0.001);
        $this->assertEqualsWithDelta(0.35, ClaimRulesService::mileageRate('motorcycle'), 0.001);
        $this->assertEqualsWithDelta(0.70, ClaimRulesService::mileageRate('unknown'), 0.001); // → car
    }

    public function test_ot_bands_pick_highest_threshold_met(): void
    {
        $this->assertEqualsWithDelta(0, ClaimRulesService::otBandAmount(2), 0.001);
        $this->assertEqualsWithDelta(50, ClaimRulesService::otBandAmount(4), 0.001);
        $this->assertEqualsWithDelta(50, ClaimRulesService::otBandAmount(7), 0.001);
        $this->assertEqualsWithDelta(100, ClaimRulesService::otBandAmount(8), 0.001);
        $this->assertEqualsWithDelta(100, ClaimRulesService::otBandAmount(12), 0.001);
    }

    public function test_compute_per_km_uses_vehicle_rate(): void
    {
        $cat = new ExpenseCategory(['rate_type' => 'per_km']);
        $this->assertEqualsWithDelta(21.0, ClaimRulesService::computeAmount($cat, ['quantity' => 30, 'vehicle' => 'car']), 0.001);
        $this->assertEqualsWithDelta(10.5, ClaimRulesService::computeAmount($cat, ['quantity' => 30, 'vehicle' => 'motorcycle']), 0.001);
        $this->assertNull(ClaimRulesService::computeAmount($cat, [])); // missing quantity
    }

    public function test_compute_per_day_and_per_hour(): void
    {
        $eventDay = new ExpenseCategory(['rate_type' => 'per_day', 'rate_amount' => 150]);
        $this->assertEqualsWithDelta(300.0, ClaimRulesService::computeAmount($eventDay, ['quantity' => 2]), 0.001);

        $extraHours = new ExpenseCategory(['rate_type' => 'per_hour']);
        $this->assertEqualsWithDelta(50.0, ClaimRulesService::computeAmount($extraHours, ['quantity' => 4]), 0.001);
        $this->assertEqualsWithDelta(100.0, ClaimRulesService::computeAmount($extraHours, ['quantity' => 8]), 0.001);
    }

    public function test_receipt_category_is_not_computed(): void
    {
        $cat = new ExpenseCategory(['rate_type' => 'receipt']);
        $this->assertNull(ClaimRulesService::computeAmount($cat, ['quantity' => 5]));
    }

    public function test_deadline_rolls_back_off_weekend(): void
    {
        // 20 Jun 2026 is a Saturday → preceding working day = Fri 19 Jun.
        $this->assertEquals('2026-06-19', ClaimRulesService::submissionDeadline(20, Carbon::create(2026, 6, 1))->toDateString());
    }

    public function test_deadline_unchanged_on_weekday(): void
    {
        // 20 May 2026 is a Wednesday.
        $this->assertEquals('2026-05-20', ClaimRulesService::submissionDeadline(20, Carbon::create(2026, 5, 1))->toDateString());
    }

    public function test_deadline_rolls_back_off_public_holiday(): void
    {
        // Force 20 Jul 2026 (a Monday) to be a holiday → rolls back to Fri 17 Jul.
        config(['claims.public_holidays' => ['2026-07-20']]);
        $this->assertEquals('2026-07-17', ClaimRulesService::submissionDeadline(20, Carbon::create(2026, 7, 1))->toDateString());
    }
}
