<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An e-waste quotation IT files on a cycle is also a document that VENDOR sent us, so it is
 * filed automatically onto their Contracts tab rather than being uploaded a second time by
 * hand. This column is the link back.
 *
 * UNIQUE, and that is the whole safety of the feature: filing runs as a side effect of the
 * quotation upload, so a retry — or a re-run of the backfill — must not be able to produce a
 * second contract row for one revision. The database refuses it rather than a query somebody
 * remembers to write.
 *
 * nullOnDelete rather than cascade: nothing deletes a quotation revision today, but if one
 * ever were removed the filed document is still a real thing the vendor sent, and destroying
 * their record of it from the other side of the link would be the wrong answer. The batch
 * reference lives on `contract_reference`, so an orphaned row can still say where it came from.
 *
 * Constraint names are given explicitly: the generated ones
 * (`vendor_contracts_asset_decommission_quotation_id_foreign`, 56 chars) are close enough to
 * MySQL's 64-char limit to be worth pinning, and an over-long name fails the ALTER *after* the
 * column is committed, leaving the migration unrecorded with half its work done.
 */
return new class extends Migration
{
    private const FK = 'vc_ewaste_quot_fk';

    private const UNIQUE = 'vc_ewaste_quot_unique';

    public function up(): void
    {
        if (Schema::hasColumn('vendor_contracts', 'asset_decommission_quotation_id')) {
            return;
        }

        Schema::table('vendor_contracts', function (Blueprint $table) {
            $table->unsignedBigInteger('asset_decommission_quotation_id')->nullable()->after('vendor_id');
            $table->unique('asset_decommission_quotation_id', self::UNIQUE);
            $table->foreign('asset_decommission_quotation_id', self::FK)
                ->references('id')->on('asset_decommission_quotations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('vendor_contracts', 'asset_decommission_quotation_id')) {
            return;
        }

        Schema::table('vendor_contracts', function (Blueprint $table) {
            $table->dropForeign(self::FK);
        });

        // The FK's own index is dropped with the unique key, so the order matters: dropping
        // the unique first would leave InnoDB without an index for the constraint (1553).
        if ($this->hasIndex(self::UNIQUE)) {
            Schema::table('vendor_contracts', function (Blueprint $table) {
                $table->dropUnique(self::UNIQUE);
            });
        }

        Schema::table('vendor_contracts', function (Blueprint $table) {
            $table->dropColumn('asset_decommission_quotation_id');
        });
    }

    private function hasIndex(string $name): bool
    {
        return DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', 'vendor_contracts')
            ->where('INDEX_NAME', $name)
            ->exists();
    }
};
