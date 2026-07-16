<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Merge the standalone "Extra Hours" expense category into "914(b)-000: Transportation".
 *
 * Business rule: extra-hours claims are filed under GL 914(b)-000 (Transportation), and that
 * GL line is used ONLY for extra hours. So there should be a single category — 914(b)-000
 * Transportation — that IS the extra-hours category: per_hour bands (4h=RM50, 8h=RM100), no
 * receipt required. The separate "Extra Hours" category is removed.
 *
 * Repurposing 914(b)-000 in place keeps its id/name/GL, so any historical items filed under it
 * (they already store their own `amount`) still display correctly; only NEW entries use the
 * per_hour, no-receipt behaviour.
 */
return new class extends Migration
{
    public function up(): void
    {
        $transportId = DB::table('expense_categories')->where('code', '914(b)-000')->value('id');

        // Repurpose 914(b)-000 → the per_hour, no-receipt extra-hours category.
        if ($transportId) {
            DB::table('expense_categories')->where('id', $transportId)->update([
                'name' => 'Transportation',
                'rate_type' => 'per_hour',
                'rate_amount' => null,
                'requires_receipt' => false,
                'description' => 'Extra working hours, paid in bands: 4 hours = RM50, 8 hours = RM100. Specify the hours worked. Can be taken as cash or replacement leave. No receipt required.',
                'keywords' => json_encode(['extra hours', 'extra hour', 'extended hours', 'after hours', 'ot', 'overtime', 'transport', 'transportation']),
                'is_active' => true,
                'updated_at' => now(),
            ]);
        } else {
            // Fresh DB without the receipt-based Transportation row: create it as extra-hours.
            $transportId = DB::table('expense_categories')->insertGetId([
                'code' => '914(b)-000',
                'gl_code' => '914(b)-000',
                'name' => 'Transportation',
                'company' => null,
                'description' => 'Extra working hours, paid in bands: 4 hours = RM50, 8 hours = RM100. Specify the hours worked. Can be taken as cash or replacement leave. No receipt required.',
                'keywords' => json_encode(['extra hours', 'extra hour', 'extended hours', 'after hours', 'ot', 'overtime', 'transport', 'transportation']),
                'monthly_limit' => null,
                'rate_type' => 'per_hour',
                'rate_amount' => null,
                'limit_period' => 'monthly',
                'applies_to_role' => null,
                'requires_receipt' => false,
                'is_active' => true,
                'sort_order' => 50,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Merge the old standalone "Extra Hours" category into 914(b)-000, then remove it.
        $extraHoursId = DB::table('expense_categories')->where('code', 'EXTRA_HOURS')->value('id');
        if ($extraHoursId && $extraHoursId !== $transportId) {
            DB::table('expense_claim_items')->where('expense_category_id', $extraHoursId)
                ->update(['expense_category_id' => $transportId]);
            DB::table('expense_categories')->where('id', $extraHoursId)->delete();
        }
    }

    /**
     * Best-effort reverse: revert 914(b)-000 to the receipt-based Transportation category and
     * recreate a standalone Extra Hours category. Items previously merged from Extra Hours are
     * NOT un-merged (the original split is not recoverable) — they stay on 914(b)-000.
     */
    public function down(): void
    {
        DB::table('expense_categories')->where('code', '914(b)-000')->update([
            'name' => 'Transportation',
            'rate_type' => 'receipt',
            'rate_amount' => null,
            'requires_receipt' => true,
            'description' => 'Transportation, etc.',
            'keywords' => json_encode(['transport', 'transportation', 'delivery', 'shipping']),
            'updated_at' => now(),
        ]);

        DB::table('expense_categories')->updateOrInsert(
            ['code' => 'EXTRA_HOURS'],
            [
                'gl_code' => '914(b)-000',
                'name' => 'Extra Hours',
                'company' => null,
                'description' => 'Extra working hours, paid in bands: 4 hours = RM50, 8 hours = RM100. Specify the hours worked. Can be taken as cash or replacement leave.',
                'keywords' => json_encode(['extra hours', 'extra hour', 'extended hours', 'after hours', 'ot', 'overtime']),
                'monthly_limit' => null,
                'rate_type' => 'per_hour',
                'rate_amount' => null,
                'limit_period' => 'monthly',
                'applies_to_role' => null,
                'requires_receipt' => false,
                'is_active' => true,
                'sort_order' => 50,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }
};
