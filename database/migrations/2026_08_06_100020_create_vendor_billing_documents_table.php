<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quotations and invoices received from a vendor — a DOCUMENT REGISTER, deliberately
 * not an AP ledger. Nothing here posts a journal entry or creates an acc_bills row;
 * it mirrors the existing "Finance stays lightweight" decision from the e-waste cycle.
 * A future push-to-Accounting step can read these rows without changing them.
 *
 * `sst_amount` is stored separately from `subtotal`/`total` because the whole point of
 * the vendor's SST category is to answer "should this line even be here?".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_billing_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained('vendors')->cascadeOnDelete();
            // A quotation/invoice may sit under a contract, or stand alone (ad-hoc purchase).
            $table->foreignId('vendor_contract_id')->nullable()->constrained('vendor_contracts')->nullOnDelete();

            $table->string('doc_type', 20);              // quotation | invoice
            $table->string('doc_number')->nullable();
            $table->string('status', 30)->default('received');

            $table->date('doc_date')->nullable();
            $table->date('due_date')->nullable();

            $table->decimal('subtotal', 15, 2)->nullable();
            $table->decimal('sst_amount', 15, 2)->nullable();
            $table->decimal('total', 15, 2)->nullable();
            $table->string('currency', 3)->default('MYR');

            $table->string('description')->nullable();
            $table->text('notes')->nullable();

            $table->string('file_path')->nullable();
            $table->string('original_filename')->nullable();

            $table->string('ocr_status', 20)->nullable();
            $table->json('ocr_raw')->nullable();
            $table->timestamp('ocr_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['vendor_id', 'doc_type']);
            $table->index('doc_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_billing_documents');
    }
};
