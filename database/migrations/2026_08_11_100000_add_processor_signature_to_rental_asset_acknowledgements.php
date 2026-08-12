<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Give the RETURN direction the same two-signatory shape the receipt already has.
 *
 * A receipt has two parties and two signatures: our receiving staff note any damage and
 * close the document, and the VENDOR'S delivery representative answers that note under
 * their own name, in their own action (`vendor_rep_acknowledged_at`), before we close it.
 *
 * A return had the same two parties but only ONE signature. The collector's tick, their
 * condition remarks and their typed identity were all committed by our processor's submit,
 * and `processor_remarks` — our reply to their note — rode along on that same submit with
 * nothing recording that we had signed it. So the document could not distinguish "the
 * collector declared this" from "we typed it on their behalf", which is precisely the
 * distinction the receipt side goes to the trouble of keeping.
 *
 * The parties swap, so the signatures swap with them:
 *
 *   Receipt — second party = the vendor's rep. No account, so a typed identity plus
 *             `vendor_rep_acknowledged_at` IS the signature.
 *   Return  — second party = OUR processor. They ARE logged in, so an account reference
 *             plus the timestamp is the signature and nothing needs typing.
 *
 * On a return `acknowledged_by` / `acknowledged_at` therefore change MEANING rather than
 * owner: the moment is the COLLECTOR'S acknowledgement (they are the closing signatory,
 * named in the collector details), and the account is the one the handover was PROCESSED
 * under. Both facts now sit on the document, which is what the old shape could not say.
 *
 * The backfill is a record, not an invention. Under the one-submit flow the acknowledging
 * account really did write `processor_remarks` on the acknowledging submit — actor and
 * moment are both known — so copying them across states what actually happened. Contrast
 * the e-waste `quotation_uploaded_by`, deliberately left null: there the actor was never
 * captured at all, and a guess would have fabricated an audit trail.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_asset_acknowledgements', function (Blueprint $table) {
            // Named explicitly. The generated name would be 62 characters — close enough to
            // MySQL's 64-char ceiling to be worth not relying on, which is the same reason
            // `raa_items_parent_fk` / `raa_items_asset_fk` are named on the items table.
            $table->foreignId('processor_acknowledged_by')
                ->nullable()
                ->after('processor_remarks')
                ->constrained('users', 'id', 'raa_processor_ack_by_fk')
                ->nullOnDelete();

            $table->timestamp('processor_acknowledged_at')
                ->nullable()
                ->after('processor_acknowledged_by');
        });

        // Returns already signed under the one-submit flow. Only rows that actually carry a
        // reply are stamped: a return with no remarks had no processor signature to record,
        // and stamping one would say somebody signed a blank.
        DB::table('rental_asset_acknowledgements')
            ->where('type', 'return')
            ->whereNotNull('processor_remarks')
            ->whereNotNull('acknowledged_at')
            ->update([
                'processor_acknowledged_by' => DB::raw('acknowledged_by'),
                'processor_acknowledged_at' => DB::raw('acknowledged_at'),
            ]);
    }

    public function down(): void
    {
        Schema::table('rental_asset_acknowledgements', function (Blueprint $table) {
            $table->dropForeign('raa_processor_ack_by_fk');
            $table->dropColumn(['processor_acknowledged_by', 'processor_acknowledged_at']);
        });
    }
};
