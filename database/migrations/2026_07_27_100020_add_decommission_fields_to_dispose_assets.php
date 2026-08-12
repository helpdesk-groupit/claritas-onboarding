<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The dispose_assets table is the decommissioning staging queue. Each row snapshots
 * an asset awaiting decommissioning. Two new columns route a staging row to a flow:
 *
 *  - decommission_type:      vendor_return | e_waste (default e_waste). Existing rows —
 *                            all created by the "Not Good" flow — default to e_waste.
 *  - decommission_batch_id:  the batch/cycle that collected it. A batch reads its line
 *                            items via `dispose_assets WHERE decommission_batch_id = X`.
 *
 * `decommission_type` is added GUARDED. The "Returned condition" slice shipped to live
 * ahead of this feature (2026-07-30) carried its own later-dated migration adding exactly
 * this column, and that one is guarded too — but a guard on the later migration only helps
 * a FRESH database, where this one runs first. On live, and on any dev box that took the
 * slice, the later migration has ALREADY run and this one has not, so an unguarded add
 * here dies on "Duplicate column name 'decommission_type'" and takes the whole deploy of
 * this feature with it. Both sides have to be guarded for the ordering to stop mattering.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dispose_assets', function (Blueprint $table) {
            if (! Schema::hasColumn('dispose_assets', 'decommission_type')) {
                $table->string('decommission_type')->default('e_waste')->after('asset_condition');
                $table->index('decommission_type');
            }
            if (! Schema::hasColumn('dispose_assets', 'decommission_batch_id')) {
                $table->unsignedBigInteger('decommission_batch_id')->nullable()->after('decommission_type');
                $table->index('decommission_batch_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('dispose_assets', function (Blueprint $table) {
            if (Schema::hasColumn('dispose_assets', 'decommission_type')) {
                $table->dropIndex(['decommission_type']);
                $table->dropColumn('decommission_type');
            }
            if (Schema::hasColumn('dispose_assets', 'decommission_batch_id')) {
                $table->dropIndex(['decommission_batch_id']);
                $table->dropColumn('decommission_batch_id');
            }
        });
    }
};
