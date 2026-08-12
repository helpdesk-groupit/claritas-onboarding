<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `asset_inventories.rental_vendor_id` becomes `vendor_id`.
 *
 * The FK was named for rentals because that was the only flow that had one. An asset can
 * equally have been PURCHASED from a registered vendor, and the vendor master now covers
 * every supplier — so the link is a single `vendor_id` whose meaning is read off the
 * existing `ownership_type`: rental ⇒ rented from, company ⇒ purchased from.
 *
 * Expressed as add → copy → drop rather than renameColumn: the column carries a foreign
 * key, and `ALTER TABLE … RENAME COLUMN` support across the MySQL/MariaDB versions this
 * app runs on is not uniform enough to bet a live migration on.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('asset_inventories', 'vendor_id')) {
            Schema::table('asset_inventories', function (Blueprint $table) {
                $table->unsignedBigInteger('vendor_id')->nullable()->after('rental_vendor');
                $table->index('vendor_id');
                $table->foreign('vendor_id')->references('id')->on('vendors')->nullOnDelete();
            });
        }

        if (Schema::hasColumn('asset_inventories', 'rental_vendor_id')) {
            DB::table('asset_inventories')
                ->whereNull('vendor_id')
                ->whereNotNull('rental_vendor_id')
                ->update(['vendor_id' => DB::raw('rental_vendor_id')]);

            Schema::table('asset_inventories', function (Blueprint $table) {
                $table->dropForeign(['rental_vendor_id']);
                $table->dropColumn('rental_vendor_id');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('asset_inventories', 'rental_vendor_id')) {
            Schema::table('asset_inventories', function (Blueprint $table) {
                $table->unsignedBigInteger('rental_vendor_id')->nullable()->after('rental_vendor');
                $table->foreign('rental_vendor_id')->references('id')->on('vendors')->nullOnDelete();
            });
        }

        if (Schema::hasColumn('asset_inventories', 'vendor_id')) {
            // Only rentals had a vendor before this migration; a purchase link has no
            // pre-existing home, so restoring it under the rental name would be a lie.
            DB::table('asset_inventories')
                ->where('ownership_type', 'rental')
                ->whereNotNull('vendor_id')
                ->update(['rental_vendor_id' => DB::raw('vendor_id')]);

            Schema::table('asset_inventories', function (Blueprint $table) {
                $table->dropForeign(['vendor_id']);
                $table->dropIndex(['vendor_id']);
                $table->dropColumn('vendor_id');
            });
        }
    }
};
