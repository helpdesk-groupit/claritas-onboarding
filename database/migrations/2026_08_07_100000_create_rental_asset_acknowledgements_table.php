<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AARF — the acknowledgement that rental assets physically changed hands between us
 * and a vendor.
 *
 * Deliberately its OWN table rather than another `type` on asset_decommission_batches.
 * Receiving rented kit is not a decommissioning: it does not archive an asset, earns
 * no money and needs no Finance approval. Folding it in would have put a receipt into
 * `$openBatches`, into Finance's Disposed listing, into ReportController's decommission
 * archive and into `recovered` — every one of which reads "batch" as "disposal".
 *
 * `type` carries the direction. The form is identical either way (the vendor confirmed
 * the two documents have the same content), so only the wording changes: `receipt` = we
 * received rental assets from the vendor, `return` = we handed them back. Today only
 * `receipt` is generated; `return` exists so the format does not have to be re-cut when
 * the other half is switched on.
 *
 * NOTE: not related to `aarfs` / App\Models\Aarf, which is the EMPLOYEE asset
 * acknowledgement (tokenized email links to a staff member). Same three letters in the
 * business vocabulary, unrelated records.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_asset_acknowledgements', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 40)->unique();
            $table->string('type', 20)->default('receipt');   // receipt | return
            $table->foreignId('vendor_id')->constrained('vendors')->cascadeOnDelete();

            // (d) Company Rented to. Copied from the assets' `company_supplied_to` at
            // generation and STORED, because one document must name one legal entity —
            // the grouping key is what the form is scoped by, not a live lookup.
            $table->string('company_rented_to')->nullable();

            $table->string('status', 20)->default('draft');   // draft | acknowledged

            // (f) The single tick box, and (g) the remarks that explain anything it
            // does not cover. Kept apart from processor_remarks so "what arrived damaged"
            // is never mixed with "what I did about it".
            $table->boolean('condition_confirmed')->default(false);
            $table->text('condition_remarks')->nullable();
            // (h) The remark of the person processing the handover.
            $table->text('processor_remarks')->nullable();

            // (i) Collector details. Pre-filled from the acknowledging user's employee
            // record but STORED on the row: the document is evidence of who stood there
            // on the day, and must not change when that person's HR record later does.
            $table->string('collector_company')->nullable();
            $table->string('collector_name')->nullable();
            $table->string('collector_ic', 60)->nullable();
            $table->string('collector_phone', 50)->nullable();

            // Two distinct system-recorded actors: who prepared the form, and who signed
            // it. Both come from the logged-in account — there is no public token here,
            // because on a receipt the collector is us.
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('acknowledged_at')->nullable();

            // Snapshot PDF, written once at acknowledgement. Private disk.
            $table->string('pdf_path')->nullable();

            $table->timestamps();

            $table->index(['vendor_id', 'status']);
            $table->index(['vendor_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_asset_acknowledgements');
    }
};
