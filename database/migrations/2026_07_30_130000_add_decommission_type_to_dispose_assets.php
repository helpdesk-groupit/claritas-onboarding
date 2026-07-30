<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The dispose_assets table is the decommissioning staging queue — one row per asset
 * awaiting decommissioning. Until now every row got there the same way (condition set
 * to "Not Good"), so the flow never had to be recorded. The "Returned" condition adds a
 * second way in, so the row now carries how it should leave:
 *
 *   e_waste       → scrapped / disposed of (the existing "Not Good" route)
 *   vendor_return → handed back to the rental vendor it belongs to
 *
 * Existing rows are all "Not Good", so the e_waste default backfills them correctly.
 *
 * Guarded with hasColumn: the fuller Asset Decommissioning feature ships its own
 * earlier-dated migration adding this same column, which would otherwise run after
 * this one and fail on a duplicate column.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('dispose_assets', 'decommission_type')) {
            return;
        }

        Schema::table('dispose_assets', function (Blueprint $table) {
            $table->string('decommission_type')->default('e_waste')->after('asset_condition');
            $table->index('decommission_type');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('dispose_assets', 'decommission_type')) {
            return;
        }

        Schema::table('dispose_assets', function (Blueprint $table) {
            $table->dropIndex(['decommission_type']);
            $table->dropColumn('decommission_type');
        });
    }
};
