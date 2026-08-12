<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Not Good" (e-waste) assets come in two states that the vendor prices differently:
 *
 *   complete   → all spare parts intact (battery, RAM, storage, …)
 *   incomplete → some parts removed
 *
 * This was previously only ever written into the free-text Notes, where neither Finance
 * nor the e-waste vendor could reliably parse it. `ewaste_completeness` captures it as a
 * structured flag on the staging row so it flows into the asset-details PDF (finance
 * report + vendor RFQ) and the Decommissioning views. Null for vendor-return rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dispose_assets', function (Blueprint $table) {
            $table->string('ewaste_completeness')->nullable()->after('reason');
        });
    }

    public function down(): void
    {
        Schema::table('dispose_assets', function (Blueprint $table) {
            $table->dropColumn('ewaste_completeness');
        });
    }
};
