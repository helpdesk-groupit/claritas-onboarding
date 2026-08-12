<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `asset_inventories.rental_vendor` changes meaning: it now holds the vendor's PIC (the
 * person we deal with), auto-filled from the vendor picker, alongside rental_vendor_contact.
 *
 * Until now buildAssetData() force-synced it to the linked vendor's COMPANY name so the
 * asset listing's vendor filter — which matched on the string — could find FK-linked
 * assets. That filter resolves through vendor_id now, so the column is free to carry the
 * PIC; but every already-linked rental asset still holds the company name and would render
 * under the new "Vendor PIC" label as though the company were a person.
 *
 * No information is lost: the company name is exactly what the FK gives back. The update is
 * therefore restricted to rows where rental_vendor is STILL the linked vendor's name — a
 * value someone has since hand-edited is real data and is left alone. Assets with no
 * vendor_id are untouched: their free text is the unregistered vendor's own name, which is
 * all they have and what the filter falls back to.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('asset_inventories')
            ->join('vendors', 'vendors.id', '=', 'asset_inventories.vendor_id')
            ->where('asset_inventories.ownership_type', 'rental')
            ->whereColumn('asset_inventories.rental_vendor', 'vendors.name')
            ->update(['asset_inventories.rental_vendor' => DB::raw('vendors.pic_name')]);
    }

    public function down(): void
    {
        // Restore the old invariant: the free-text column mirrors the linked vendor's name.
        // Rows whose PIC was already null come back as the company name too, which is what
        // the pre-change sync would have written on the next save either way.
        DB::table('asset_inventories')
            ->join('vendors', 'vendors.id', '=', 'asset_inventories.vendor_id')
            ->where('asset_inventories.ownership_type', 'rental')
            ->where(function ($q) {
                $q->whereNull('asset_inventories.rental_vendor')
                    ->orWhereColumn('asset_inventories.rental_vendor', 'vendors.pic_name');
            })
            ->update(['asset_inventories.rental_vendor' => DB::raw('vendors.name')]);
    }
};
