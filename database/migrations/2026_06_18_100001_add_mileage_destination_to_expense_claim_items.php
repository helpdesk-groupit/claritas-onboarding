<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Persist the typed destination for Petrol "by mileage" claims so a reviewer can
 * re-verify the distance later (the destination was previously used only for the
 * client-side km lookup and then discarded). Nullable — only set on mileage items.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expense_claim_items', function (Blueprint $table) {
            $table->string('mileage_destination', 255)->nullable()->after('rate_applied');
        });
    }

    public function down(): void
    {
        Schema::table('expense_claim_items', function (Blueprint $table) {
            $table->dropColumn('mileage_destination');
        });
    }
};
