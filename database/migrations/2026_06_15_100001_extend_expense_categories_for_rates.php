<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extends expense_categories so a category can express richer rules than a
 * single monthly cap:
 *  - rate_type: how the line amount is derived
 *      receipt  → amount comes from the receipt (default, existing behaviour)
 *      per_km   → amount = km × vehicle mileage rate (config/claims.php)
 *      per_day  → amount = days × rate_amount (e.g. RM150/day events)
 *      per_hour → amount = OT band lookup by hours (config/claims.php)
 *  - rate_amount: the unit rate for per_day (and reference for fixed allowances)
 *  - limit_period: whether monthly_limit is a monthly or annual cap
 *  - applies_to_role: restrict the category to a role group (e.g. 'intern')
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expense_categories', function (Blueprint $table) {
            $table->enum('rate_type', ['receipt', 'per_km', 'per_day', 'per_hour'])
                ->default('receipt')->after('monthly_limit');
            $table->decimal('rate_amount', 10, 2)->nullable()->after('rate_type');
            $table->enum('limit_period', ['monthly', 'annual'])
                ->default('monthly')->after('rate_amount');
            $table->string('applies_to_role', 50)->nullable()->after('limit_period');
        });
    }

    public function down(): void
    {
        Schema::table('expense_categories', function (Blueprint $table) {
            $table->dropColumn(['rate_type', 'rate_amount', 'limit_period', 'applies_to_role']);
        });
    }
};
