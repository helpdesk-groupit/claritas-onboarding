<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5 — a quotation belongs to a VENDOR, not just to a cycle.
 *
 * The cycle now RFQs every active e-waste vendor and the offers are compared on price, so a
 * cycle holds several quotations at once. Each vendor still has its own re-quote loop, so the
 * revision number becomes per (batch, vendor) rather than per batch: vendor A's revision 2
 * answers vendor A's rejected revision 1, and has nothing to do with vendor B's first offer.
 *
 * ORDER MATTERS. The new unique index is created BEFORE the old one is dropped, and both lead
 * with asset_decommission_batch_id. That column carries a foreign key, and InnoDB requires an
 * index on it — dropping the only one first fails with "needed in a foreign key constraint"
 * (1553) *after* the column and its FK have already been committed, since MySQL DDL is not
 * transactional. Every step is therefore also written to be re-runnable, so a migration that
 * died halfway can simply be run again.
 *
 * A NULL vendor_id does not collide under MySQL's unique semantics, which is what lets the
 * legacy rows below sit alongside the new ones.
 *
 * Backfill: existing revisions belong to the batch's single RFQ'd vendor. Where the batch has
 * no vendor (the RFQ was skipped because no primary e-waste vendor was set) the quotation
 * keeps a null vendor rather than being assigned to one — nothing in the data says who sent
 * it, and inventing a vendor would attribute a real financial document to a company that may
 * never have quoted.
 */
return new class extends Migration
{
    private const OLD_INDEX = 'adq_batch_revision_unique';

    private const NEW_INDEX = 'adq_batch_vendor_revision_unique';

    public function up(): void
    {
        if (! Schema::hasColumn('asset_decommission_quotations', 'vendor_id')) {
            Schema::table('asset_decommission_quotations', function (Blueprint $table) {
                $table->unsignedBigInteger('vendor_id')->nullable()->after('asset_decommission_batch_id');
                $table->foreign('vendor_id', 'adq_vendor_fk')->references('id')->on('vendors')->nullOnDelete();
            });
        }

        DB::statement('
            UPDATE asset_decommission_quotations q
            JOIN asset_decommission_batches b ON b.id = q.asset_decommission_batch_id
            SET q.vendor_id = b.vendor_id
            WHERE q.vendor_id IS NULL AND b.vendor_id IS NOT NULL
        ');

        // New index first — it also leads with the FK column, so the old one becomes droppable.
        if (! $this->hasIndex(self::NEW_INDEX)) {
            Schema::table('asset_decommission_quotations', function (Blueprint $table) {
                $table->unique(['asset_decommission_batch_id', 'vendor_id', 'revision'], self::NEW_INDEX);
            });
        }

        if ($this->hasIndex(self::OLD_INDEX)) {
            Schema::table('asset_decommission_quotations', function (Blueprint $table) {
                $table->dropUnique(self::OLD_INDEX);
            });
        }
    }

    public function down(): void
    {
        // Mirror image: restore the old index before removing the new one, for the same
        // foreign-key reason.
        if (! $this->hasIndex(self::OLD_INDEX)) {
            Schema::table('asset_decommission_quotations', function (Blueprint $table) {
                $table->unique(['asset_decommission_batch_id', 'revision'], self::OLD_INDEX);
            });
        }

        if ($this->hasIndex(self::NEW_INDEX)) {
            Schema::table('asset_decommission_quotations', function (Blueprint $table) {
                $table->dropUnique(self::NEW_INDEX);
            });
        }

        if (Schema::hasColumn('asset_decommission_quotations', 'vendor_id')) {
            Schema::table('asset_decommission_quotations', function (Blueprint $table) {
                $table->dropForeign('adq_vendor_fk');
                $table->dropColumn('vendor_id');
            });
        }
    }

    private function hasIndex(string $name): bool
    {
        return DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', 'asset_decommission_quotations')
            ->where('INDEX_NAME', $name)
            ->exists();
    }
};
