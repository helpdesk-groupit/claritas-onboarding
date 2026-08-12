<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contracts we hold with a vendor — the "what have we actually agreed with them"
 * half of the vendor profile page.
 *
 * Every extracted field is nullable and editable: the OCR pre-fills, a human owns
 * the value. `ocr_raw` keeps the model's full reply so a field that was read wrong
 * can be traced back to what the document actually said, rather than only to what
 * we stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained('vendors')->cascadeOnDelete();

            $table->string('title');
            $table->string('contract_reference')->nullable();
            $table->string('contract_type', 60)->nullable();
            $table->string('status', 30)->default('active');

            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            // Null end_date + auto_renew is an evergreen contract; both are legitimate.
            $table->boolean('auto_renew')->default(false);
            $table->unsignedSmallInteger('notice_period_days')->nullable();

            $table->decimal('contract_value', 15, 2)->nullable();
            $table->string('currency', 3)->default('MYR');
            $table->string('billing_cycle', 30)->nullable();
            $table->string('payment_terms')->nullable();

            $table->text('scope_summary')->nullable();
            $table->text('notes')->nullable();

            // The document itself. Private disk, served through SecureFileController.
            $table->string('file_path')->nullable();
            $table->string('original_filename')->nullable();

            // OCR provenance — 'pending' only exists transiently; a document that could
            // not be read lands on 'failed' or 'skipped' and says so on the page rather
            // than silently looking like a blank contract someone forgot to fill in.
            $table->string('ocr_status', 20)->nullable();
            $table->json('ocr_raw')->nullable();
            $table->timestamp('ocr_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['vendor_id', 'status']);
            $table->index('end_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_contracts');
    }
};
