<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Seed leave entitlements based on Malaysian Employment Act 1955 (amended 2022).
     * Only inserts entitlements for leave types that have zero existing entitlements.
     */
    public function up(): void
    {
        $defaults = [
            // Annual Leave – s.60E EA 1955
            'AL' => [
                ['min_tenure_months' => 0,  'max_tenure_months' => 24,   'entitled_days' => 8,  'carry_forward_limit' => 5],
                ['min_tenure_months' => 24, 'max_tenure_months' => 60,   'entitled_days' => 12, 'carry_forward_limit' => 5],
                ['min_tenure_months' => 60, 'max_tenure_months' => null,  'entitled_days' => 16, 'carry_forward_limit' => 8],
            ],
            // Sick Leave (outpatient) – s.60F(1)(a) EA 1955
            'SL' => [
                ['min_tenure_months' => 0,  'max_tenure_months' => 24,   'entitled_days' => 14, 'carry_forward_limit' => 0],
                ['min_tenure_months' => 24, 'max_tenure_months' => 60,   'entitled_days' => 18, 'carry_forward_limit' => 0],
                ['min_tenure_months' => 60, 'max_tenure_months' => null,  'entitled_days' => 22, 'carry_forward_limit' => 0],
            ],
            // Medical Leave – s.60F (same scale as SL for employers who separate them)
            'ML' => [
                ['min_tenure_months' => 0,  'max_tenure_months' => 24,   'entitled_days' => 14, 'carry_forward_limit' => 0],
                ['min_tenure_months' => 24, 'max_tenure_months' => 60,   'entitled_days' => 18, 'carry_forward_limit' => 0],
                ['min_tenure_months' => 60, 'max_tenure_months' => null,  'entitled_days' => 22, 'carry_forward_limit' => 0],
            ],
            // Hospitalisation Leave – s.60F(1)(bb) EA 1955 (60 days inclusive of SL)
            'HL' => [
                ['min_tenure_months' => 0, 'max_tenure_months' => null, 'entitled_days' => 60, 'carry_forward_limit' => 0],
            ],
            // Compassionate Leave – common practice (not in EA 1955)
            'CL' => [
                ['min_tenure_months' => 0, 'max_tenure_months' => null, 'entitled_days' => 3, 'carry_forward_limit' => 0],
            ],
            // Maternity Leave – s.37(1)(a) EA 1955 (amended 2022: 98 days)
            'MATL' => [
                ['min_tenure_months' => 0, 'max_tenure_months' => null, 'entitled_days' => 98, 'carry_forward_limit' => 0],
            ],
            // Paternity Leave – s.60FA EA 1955 (amended 2022: 7 days)
            'PATL' => [
                ['min_tenure_months' => 0, 'max_tenure_months' => null, 'entitled_days' => 7, 'carry_forward_limit' => 0],
            ],
            // Replacement Leave – earned when working on rest day/public holiday
            'RL' => [
                ['min_tenure_months' => 0, 'max_tenure_months' => null, 'entitled_days' => 0, 'carry_forward_limit' => 0],
            ],
            // Unpaid Leave – no statutory entitlement
            'UL' => [
                ['min_tenure_months' => 0, 'max_tenure_months' => null, 'entitled_days' => 0, 'carry_forward_limit' => 0],
            ],
        ];

        $now = now();

        foreach ($defaults as $code => $tiers) {
            $leaveType = DB::table('leave_types')->where('code', $code)->first();
            if (!$leaveType) {
                continue;
            }

            // Only seed if this leave type has no entitlements yet
            $existing = DB::table('leave_entitlements')->where('leave_type_id', $leaveType->id)->count();
            if ($existing > 0) {
                continue;
            }

            foreach ($tiers as $tier) {
                DB::table('leave_entitlements')->insert([
                    'leave_type_id'      => $leaveType->id,
                    'company'            => null,
                    'min_tenure_months'  => $tier['min_tenure_months'],
                    'max_tenure_months'  => $tier['max_tenure_months'],
                    'entitled_days'      => $tier['entitled_days'],
                    'carry_forward_limit'=> $tier['carry_forward_limit'],
                    'created_at'         => $now,
                    'updated_at'         => $now,
                ]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
