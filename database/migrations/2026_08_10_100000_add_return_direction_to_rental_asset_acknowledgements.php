<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Switch the AARF on for the RETURN direction — handing rental assets back to the vendor.
 *
 * Two changes, and both exist because the same asset now legitimately appears on TWO
 * documents in its life: the receipt when it arrived, and the return when it went back.
 *
 * 1. `UNIQUE(asset_inventory_id)` becomes `UNIQUE(asset_inventory_id, direction)`.
 *
 *    The original index says "an asset is acknowledged once, ever", which was right while
 *    only receipts existed and is the reason `pendingAssetsFor()` can be a simple
 *    `whereNotIn`. Left alone it would have silently barred every asset that was ever
 *    receipted from ever being returned — the assets on a return form are precisely the
 *    ones already on a receipt form.
 *
 *    The direction is DENORMALISED onto the item row rather than joined from the parent,
 *    because a composite unique index cannot reach across tables and the original
 *    docblock's argument still holds: "not yet acknowledged" must be a fact the database
 *    enforces, not a query somebody has to remember to write. It is written once at
 *    creation from the parent's `type` (RentalAssetAcknowledgementItem::snapshotFrom()
 *    takes it as a required argument) and the parent's type never changes.
 *
 *    The column defaults to `receipt` deliberately. If a code path ever forgets to set it,
 *    a return item lands on the receipt slot and collides with the asset's real receipt
 *    row — a loud duplicate-key failure at insert. The alternative default (nullable)
 *    would be silent: MySQL does not collide NULLs in a unique index, so every unset row
 *    would slip past the very constraint this migration exists to preserve.
 *
 * 2. `processor_remarks` comes back on the parent.
 *
 *    The column existed, and `2026_08_07_100020` renamed it to `vendor_rep_remarks`
 *    because on a RECEIPT the second party is the vendor's delivery rep, not us. On a
 *    RETURN the two parties swap: the vendor's collector notes anything not received in
 *    good condition (`condition_remarks`), and OUR staff processing the return answer it.
 *    That answer needs its own column named for the party that actually writes it —
 *    reusing `vendor_rep_remarks` for our own staff would repeat exactly the mistake that
 *    rename was made to fix. Each direction fills its own column and leaves the other null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_asset_acknowledgement_items', function (Blueprint $table) {
            $table->string('direction', 20)
                ->default('receipt')
                ->after('rental_asset_acknowledgement_id');
        });

        // Every existing row is a receipt (only that direction was ever generated), but read
        // it off the parent rather than assuming — the parent's `type` is the authority.
        DB::statement('
            UPDATE rental_asset_acknowledgement_items AS i
            JOIN rental_asset_acknowledgements AS a
              ON a.id = i.rental_asset_acknowledgement_id
            SET i.direction = a.type
        ');

        Schema::table('rental_asset_acknowledgement_items', function (Blueprint $table) {
            // Add the replacement BEFORE dropping the original. `asset_inventory_id` carries
            // the `raa_items_asset_fk` foreign key, and MySQL refuses to drop the last index
            // that can back it. The new index has the same leftmost column, so once it exists
            // the old one is redundant and drops cleanly.
            $table->unique(['asset_inventory_id', 'direction'], 'raa_items_asset_direction_unique');
            $table->dropUnique('raa_items_asset_unique');
        });

        Schema::table('rental_asset_acknowledgements', function (Blueprint $table) {
            $table->text('processor_remarks')->nullable()->after('condition_remarks');
        });
    }

    public function down(): void
    {
        Schema::table('rental_asset_acknowledgements', function (Blueprint $table) {
            $table->dropColumn('processor_remarks');
        });

        // Reversing narrows the constraint again, so an asset that sits on both a receipt
        // and a return would break it. Drop the return rows first — they are the records
        // this migration introduced, and rolling back is a decision to un-introduce them.
        DB::table('rental_asset_acknowledgement_items')->where('direction', 'return')->delete();
        DB::table('rental_asset_acknowledgements')->where('type', 'return')->delete();

        Schema::table('rental_asset_acknowledgement_items', function (Blueprint $table) {
            $table->unique('asset_inventory_id', 'raa_items_asset_unique');
            $table->dropUnique('raa_items_asset_direction_unique');
            $table->dropColumn('direction');
        });
    }
};
