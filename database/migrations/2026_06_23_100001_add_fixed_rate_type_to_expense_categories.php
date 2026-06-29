<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds a 'fixed' rate_type to expense_categories — a flat-subsidy category whose
 * claimable amount is always rate_amount (e.g. season/office parking at RM80/month),
 * irrespective of the receipt total.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE expense_categories MODIFY rate_type ENUM('receipt','per_km','per_day','per_hour','fixed') NOT NULL DEFAULT 'receipt'");
    }

    public function down(): void
    {
        // Revert any 'fixed' rows to 'receipt' before narrowing the enum back.
        DB::table('expense_categories')->where('rate_type', 'fixed')->update(['rate_type' => 'receipt']);
        DB::statement("ALTER TABLE expense_categories MODIFY rate_type ENUM('receipt','per_km','per_day','per_hour') NOT NULL DEFAULT 'receipt'");
    }
};
