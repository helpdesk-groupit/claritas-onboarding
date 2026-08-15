<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The asset's specification, as a Section A snapshot column on the AARF line.
 *
 * SNAPSHOT, like every other column on this table and for the same reason: the signed form
 * states what was physically handed over on the day. Re-keying a RAM size six months later
 * must not silently rewrite a document somebody put their name to, so this is stored rather
 * than read through the FK at render time — even though `AssetInventory::specSummary()` is
 * one call away.
 *
 * One string, not six columns. The vendor profile's Assets tab already renders the spec as a
 * single widest-to-narrowest line (processor · ram · storage · OS · screen · others) and the
 * form must not describe an asset a second way; `specSummary()` stays the one builder.
 *
 * The backfill is deliberately limited to DRAFT forms. An unsigned form has not been put in
 * front of anybody, so filling it from the asset is exactly what raising it today would
 * produce. An ALREADY-SIGNED form is left null and prints "—": nobody can know what the
 * spec said the day it was signed, and writing today's value into it would be inventing a
 * detail of a signed document. Same rule as the un-backfilled `quotation_uploaded_by`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_asset_acknowledgement_items', function (Blueprint $table) {
            $table->string('spec_summary', 500)->nullable()->after('serial_number');
        });

        $this->backfillDrafts();
    }

    public function down(): void
    {
        Schema::table('rental_asset_acknowledgement_items', function (Blueprint $table) {
            $table->dropColumn('spec_summary');
        });
    }

    /**
     * Fill in the spec on lines belonging to forms nobody has signed yet.
     *
     * Built here rather than through the model so the migration keeps behaving as it did the
     * day it ran, whatever `specSummary()` becomes later — the same rule the asset↔invoice
     * backfill follows with its own copy of normaliseInvoiceReference().
     */
    private function backfillDrafts(): void
    {
        $lines = DB::table('rental_asset_acknowledgement_items as i')
            ->join('rental_asset_acknowledgements as a', 'a.id', '=', 'i.rental_asset_acknowledgement_id')
            ->join('asset_inventories as x', 'x.id', '=', 'i.asset_inventory_id')
            ->where('a.status', 'draft')
            ->select([
                'i.id',
                'x.processor', 'x.ram_size', 'x.storage',
                'x.operating_system', 'x.screen_size', 'x.spec_others',
            ])
            ->get();

        foreach ($lines as $line) {
            $spec = collect([
                $line->processor, $line->ram_size, $line->storage,
                $line->operating_system, $line->screen_size, $line->spec_others,
            ])->map(fn ($v) => trim((string) $v))->filter()->implode(' · ');

            if ($spec === '') {
                continue;
            }

            DB::table('rental_asset_acknowledgement_items')
                ->where('id', $line->id)
                ->update(['spec_summary' => mb_substr($spec, 0, 500)]);
        }
    }
};
