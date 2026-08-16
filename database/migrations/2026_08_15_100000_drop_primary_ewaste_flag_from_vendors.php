<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retire the "primary e-waste vendor" flag.
 *
 * The quarterly sweep has RFQ'd EVERY active e-waste vendor with a PIC email since the
 * offer-comparison work landed — asking one vendor made "compare the offers and take the
 * best price" impossible, because there was only ever one offer. The flag survived after
 * that only as the default `vendor_id` stamped on a fresh cycle, which is a placeholder
 * overwritten the moment management select a winner, and as a warning banner that told
 * operators the sweep "cannot send an RFQ" — which had stopped being true.
 *
 * A boolean nothing reads is worse than no column: the next reader has to work out whether
 * it still steers where our disposal is offered. So it goes, together with its index.
 *
 * `down()` re-adds the column but CANNOT restore which vendor held it — the flag is a
 * choice, not a derivable fact, and inventing one would silently re-point an RFQ. Every
 * row comes back false; the sweep's behaviour is identical either way.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('vendors', 'is_primary_ewaste')) {
            return;
        }

        Schema::table('vendors', function (Blueprint $table) {
            // MySQL drops a single-column index with its column, but naming it keeps the
            // intent explicit and the migration honest about what it removes.
            $table->dropIndex(['is_primary_ewaste']);
        });

        Schema::table('vendors', function (Blueprint $table) {
            $table->dropColumn('is_primary_ewaste');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('vendors', 'is_primary_ewaste')) {
            return;
        }

        Schema::table('vendors', function (Blueprint $table) {
            $table->boolean('is_primary_ewaste')->default(false)->after('website');
            $table->index('is_primary_ewaste');
        });
    }
};
