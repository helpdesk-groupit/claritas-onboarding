<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds per-unit fields to claim items so computed categories (mileage, event
 * day rate, OT bands) can record how the amount was derived and remain
 * auditable. Mileage-specific fields (origin/destination) are added in the
 * Google Maps phase.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expense_claim_items', function (Blueprint $table) {
            $table->decimal('quantity', 8, 2)->nullable()->after('amount');   // km / days / hours
            $table->string('unit', 10)->nullable()->after('quantity');        // km / day / hour
            $table->decimal('rate_applied', 10, 2)->nullable()->after('unit'); // unit rate used
        });
    }

    public function down(): void
    {
        Schema::table('expense_claim_items', function (Blueprint $table) {
            $table->dropColumn(['quantity', 'unit', 'rate_applied']);
        });
    }
};
