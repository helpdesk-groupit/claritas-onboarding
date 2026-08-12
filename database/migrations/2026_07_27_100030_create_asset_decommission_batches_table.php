<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The collection record AND the C-Suite report for a decommissioning event.
 * One table, `type`-discriminated, with two distinct lifecycles:
 *
 *   vendor_return: draft → sent → acknowledged → cancelled
 *   e_waste:       awaiting_quotation → quotation_uploaded
 *                  → finance_approved | finance_rejected → collected → completed → cancelled
 *
 * Line items live in dispose_assets WHERE decommission_batch_id = <this batch id>.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_decommission_batches', function (Blueprint $table) {
            $table->id();
            $table->string('batch_number')->unique(); // RET-2026-0001 | EWA-2026-Q3
            $table->string('type');                    // vendor_return | e_waste
            $table->unsignedBigInteger('vendor_id')->nullable();
            $table->string('status');
            $table->string('report_pdf_path')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->text('notes')->nullable();

            // ── Flow 1 (vendor_return) — collector identity + tokenized ack ──
            $table->string('collector_name')->nullable();
            $table->string('collector_ic')->nullable();
            $table->string('collector_phone')->nullable();
            $table->string('collector_email')->nullable();
            $table->string('acknowledgement_token', 64)->nullable()->unique();
            $table->timestamp('acknowledged_at')->nullable();
            $table->string('acknowledged_ip')->nullable();
            $table->string('acknowledged_name')->nullable();
            $table->string('acknowledged_ic')->nullable();

            // ── Flow 2 (e_waste) — RFQ → quotation → finance → receipt ──
            $table->timestamp('rfq_sent_at')->nullable();
            $table->timestamp('finance_report_sent_at')->nullable();
            $table->string('quotation_path')->nullable();
            $table->decimal('quotation_amount', 12, 2)->nullable(); // what the vendor will PAY US
            $table->timestamp('quotation_uploaded_at')->nullable();
            $table->string('finance_status')->nullable(); // pending | approved | rejected
            $table->unsignedBigInteger('finance_reviewed_by')->nullable();
            $table->timestamp('finance_reviewed_at')->nullable();
            $table->text('finance_remarks')->nullable();
            $table->string('receipt_path')->nullable();
            $table->decimal('receipt_amount', 12, 2)->nullable(); // proof of payment received
            $table->timestamp('receipt_uploaded_at')->nullable();

            $table->timestamps();

            $table->index('type');
            $table->index('status');
            $table->index('finance_status');
            $table->foreign('vendor_id')->references('id')->on('vendors')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_decommission_batches');
    }
};
