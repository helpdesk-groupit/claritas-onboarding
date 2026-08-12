<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the soft-archive marker + decommission linkage to asset_inventories.
 *
 *  - decommissioned_at:      once set, the asset is excluded from every inventory
 *                            view (listing + Decommissioning tab). Never hard-deleted.
 *  - decommission_batch_id:  the batch/cycle that collected it (asset_decommission_batches.id).
 *                            Kept as a plain unsignedBigInt (no FK) because that table is
 *                            created by a later migration.
 *  - rental_vendor_id:       optional FK to the operational vendors table for rental assets
 *                            (Flow 1 pulls the PIC from here). Free-text rental_vendor stays
 *                            for legacy/back-compat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asset_inventories', function (Blueprint $table) {
            $table->timestamp('decommissioned_at')->nullable()->after('remarks');
            $table->unsignedBigInteger('decommission_batch_id')->nullable()->after('decommissioned_at');
            $table->unsignedBigInteger('rental_vendor_id')->nullable()->after('rental_vendor');

            $table->index('decommissioned_at');
            $table->index('decommission_batch_id');

            $table->foreign('rental_vendor_id')->references('id')->on('vendors')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('asset_inventories', function (Blueprint $table) {
            $table->dropForeign(['rental_vendor_id']);
            $table->dropIndex(['decommissioned_at']);
            $table->dropIndex(['decommission_batch_id']);
            $table->dropColumn(['decommissioned_at', 'decommission_batch_id', 'rental_vendor_id']);
        });
    }
};
