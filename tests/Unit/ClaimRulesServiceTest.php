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

    public function test_mileage_claim_excludes_receipt_categories_on_the_mileage_gl(): void
    {
        config(['claims.mileage.gl_code' => '919-000', 'claims.mileage.receipt_categories' => ['CLARITAS_SUPPORT_ALLOWANCE']]);

        $petrol = new ExpenseCategory(['gl_code' => '919-000', 'code' => '919-000']);
        $support = new ExpenseCategory(['gl_code' => '919-000', 'code' => 'CLARITAS_SUPPORT_ALLOWANCE']);
        $travel = new ExpenseCategory(['gl_code' => '905-000', 'code' => 'TRAVEL']);

        $this->assertTrue($petrol->isMileageClaim());   // real petrol → distance × rate
        $this->assertFalse($support->isMileageClaim());  // support allowance → actual receipt amount
        $this->assertFalse($travel->isMileageClaim());   // not on the mileage GL at all
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

    public function test_category_match_score_is_word_boundary_aware(): void
    {
        $pad = fn ($s) => ' '.strtolower(preg_replace('/[^a-z0-9\s]/i', ' ', $s)).' ';

        $training = new ExpenseCategory(['name' => 'Seminar & Training', 'keywords' => ['training', 'workshop', 'course']]);
        // "ot" must NOT match inside "robotics"; "training" should score.
        $this->assertGreaterThan(0, $training->descriptionMatchScore($pad('Robotics workshop training')));

        $ot = new ExpenseCategory(['name' => 'Extra Hours', 'keywords' => ['ot']]);
        $this->assertSame(0.0, $ot->descriptionMatchScore($pad('Robotics promotion notes')));
        $this->assertGreaterThan(0, $ot->descriptionMatchScore($pad('claimed ot for late night')));

        // Phrase keyword outweighs a single word; more specific category wins.
        $food = new ExpenseCategory(['name' => 'Office Food & Refreshment', 'keywords' => ['lunch', 'food']]);
        $entertainment = new ExpenseCategory(['name' => 'Entertainment', 'keywords' => ['client lunch', 'client dinner']]);
        $this->assertGreaterThan(
            $food->descriptionMatchScore($pad('client lunch with vendor')),
            $entertainment->descriptionMatchScore($pad('client lunch with vendor'))
        );
    }

    public function test_fixed_category_returns_flat_rate_amount(): void
    {
        // Season parking: amount is always the flat rate, no quantity involved.
        $cat = new ExpenseCategory(['rate_type' => 'fixed', 'rate_amount' => 80]);
        $this->assertTrue($cat->isFixed());
        $this->assertTrue($cat->isComputed());
        $this->assertEqualsWithDelta(80.0, ClaimRulesService::computeAmount($cat, []), 0.001);
        $this->assertEqualsWithDelta(80.0, ClaimRulesService::computeAmount($cat, ['quantity' => 5]), 0.001); // qty ignored
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
