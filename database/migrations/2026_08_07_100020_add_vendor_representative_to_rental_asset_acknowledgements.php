<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An AARF has TWO sides, not one.
 *
 * The original table assumed a single signatory — us. In practice a handover has two
 * parties standing at the desk: our receiving staff, who note any damage they can see
 * (`condition_remarks`), and the VENDOR'S delivery representative, who answers that note
 * and signs their own name to the answer.
 *
 * `processor_remarks` was named for the wrong person — it reads as "whoever processed
 * this", which is us, when it is in fact the vendor's rep replying to our damage note.
 * Renamed rather than left alone: a column that names the wrong party is how the wrong
 * person ends up being held to what it says.
 *
 * Their remarks and their identity are written in ONE action, so the state "a damage
 * reply nobody signed" cannot exist in the table. That is the whole reason the rep's
 * details live here rather than being optional fields on the main acknowledgement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_asset_acknowledgements', function (Blueprint $table) {
            $table->renameColumn('processor_remarks', 'vendor_rep_remarks');
        });

        Schema::table('rental_asset_acknowledgements', function (Blueprint $table) {
            $table->string('vendor_rep_company')->nullable()->after('vendor_rep_remarks');
            $table->string('vendor_rep_name')->nullable()->after('vendor_rep_company');
            $table->string('vendor_rep_ic', 60)->nullable()->after('vendor_rep_name');
            $table->string('vendor_rep_phone', 50)->nullable()->after('vendor_rep_ic');
            // The rep signs in person, on our screen — there is no account behind them,
            // so the timestamp plus the typed identity IS the signature.
            $table->timestamp('vendor_rep_acknowledged_at')->nullable()->after('vendor_rep_phone');
        });
    }

    public function down(): void
    {
        Schema::table('rental_asset_acknowledgements', function (Blueprint $table) {
            $table->dropColumn([
                'vendor_rep_company', 'vendor_rep_name', 'vendor_rep_ic',
                'vendor_rep_phone', 'vendor_rep_acknowledged_at',
            ]);
        });

        Schema::table('rental_asset_acknowledgements', function (Blueprint $table) {
            $table->renameColumn('vendor_rep_remarks', 'processor_remarks');
        });
    }
};
