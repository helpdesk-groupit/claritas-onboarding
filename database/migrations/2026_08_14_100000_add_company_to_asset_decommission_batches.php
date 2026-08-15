<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 — one collection cycle per company.
 *
 * The quarterly sweep used to gather every company's assets into a single EWA batch. From
 * Phase 5 the management approver who authorises a disposal is per-company (Claritas → Kelvin;
 * Enlinea → Kelvin or Petrina), so a mixed batch would have no single party able to sign it,
 * and its report would name the wrong legal entity on the vendor's paperwork.
 *
 * Nullable because every batch created before this migration is a mixed one — inventing a
 * company for it would misstate whose assets were disposed of. Those read "not recorded".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asset_decommission_batches', function (Blueprint $table) {
            $table->string('company')->nullable()->after('type');
            $table->index('company', 'adb_company_idx');
        });
    }

    public function down(): void
    {
        Schema::table('asset_decommission_batches', function (Blueprint $table) {
            $table->dropIndex('adb_company_idx');
            $table->dropColumn('company');
        });
    }
};
