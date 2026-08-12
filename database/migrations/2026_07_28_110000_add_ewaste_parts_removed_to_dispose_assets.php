<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a "Not Good" e-waste asset is marked Incomplete, IT lists WHICH parts were
 * removed (battery, RAM, hard disk, …). Captured here as a short free-text list on
 * the staging row so it flows into the asset-details PDF (finance report + vendor RFQ)
 * and the Decommissioning views, instead of being buried in Notes. Null unless the
 * asset is an incomplete e-waste item.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dispose_assets', function (Blueprint $table) {
            $table->string('ewaste_parts_removed', 500)->nullable()->after('ewaste_completeness');
        });
    }

    public function down(): void
    {
        Schema::table('dispose_assets', function (Blueprint $table) {
            $table->dropColumn('ewaste_parts_removed');
        });
    }
};
