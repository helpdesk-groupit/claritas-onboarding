<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One line per asset on an AARF — Section A of the asset listing and nothing else
 * (asset tag, name, category, type, brand, model, serial number).
 *
 * Every Section A field is SNAPSHOT here rather than read through the FK at render
 * time. The signed form states what was physically handed over on the day; re-typing
 * a serial number or re-branding an asset six months later must not silently rewrite
 * a document somebody already put their name to. The FK stays so the asset can still
 * be reached from the form (and to prove which asset it was), but it is never the
 * source of the printed row.
 *
 * UNIQUE(asset_inventory_id) — across ALL acknowledgements, not per form. An asset is
 * acknowledged once; that constraint is what makes "not yet acknowledged" a fact the
 * database enforces rather than a query someone has to remember to write. Deleting a
 * draft frees its assets again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_asset_acknowledgement_items', function (Blueprint $table) {
            $table->id();
            // Both foreign keys are named explicitly. Laravel's generated name here would
            // be `rental_asset_acknowledgement_items_rental_asset_acknowledgement_id_foreign`
            // — 74 characters, past MySQL's 64-char identifier limit, so the ALTER fails
            // AFTER the CREATE has already succeeded and the migration half-applies.
            $table->foreignId('rental_asset_acknowledgement_id')
                ->constrained('rental_asset_acknowledgements', 'id', 'raa_items_parent_fk')
                ->cascadeOnDelete();
            $table->foreignId('asset_inventory_id')
                ->constrained('asset_inventories', 'id', 'raa_items_asset_fk')
                ->cascadeOnDelete();

            // Section A snapshot.
            $table->string('asset_tag')->nullable();
            $table->string('asset_name')->nullable();
            $table->string('asset_category')->nullable();
            $table->string('asset_type')->nullable();
            $table->string('brand')->nullable();
            $table->string('model')->nullable();
            $table->string('serial_number')->nullable();

            $table->timestamps();

            // An asset belongs to at most one acknowledgement, ever. No separate index on
            // the parent id — the foreign key above already provides one.
            $table->unique('asset_inventory_id', 'raa_items_asset_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_asset_acknowledgement_items');
    }
};
