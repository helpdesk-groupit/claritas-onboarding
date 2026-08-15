<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Proof that an invoice in the vendor billing register was actually paid.
 *
 * ONE ROW PER INVOICE, enforced by the database rather than by a query somebody remembers
 * to write — `UNIQUE(vendor_billing_document_id)`. The Billing tab's Status column is
 * DERIVED from the presence of this row (Paid vs Pending), so a second slip for the same
 * invoice would not just be untidy: it would make "is this bill settled?" a question with
 * two answers depending on which row was read first. Re-uploading REPLACES the row and its
 * file, which is the only way one invoice ever changes its slip.
 *
 * Its own table, deliberately not columns on `vendor_billing_documents`: the slip is a
 * document in its own right and carries its own reading — summary, key points and a
 * transcription — which would collide head-on with the invoice's `ai_*` columns.
 *
 * `cascadeOnDelete`, because a payment slip is meaningless without the invoice it pays.
 * Note the FILE is not cascaded by the database — VendorBillingController::destroy() has
 * to delete it, exactly as it already does for the invoice's own document.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_payment_slips', function (Blueprint $table) {
            $table->id();

            // Named explicitly: Laravel's generated name for this one would be
            // `vendor_payment_slips_vendor_billing_document_id_foreign`, which is inside
            // MySQL's 64-character limit but only just — and a longer column name added
            // later would fail the ALTER *after* the CREATE succeeded, leaving the table
            // present and the migration unrecorded. The AARF items table was bitten by
            // exactly that.
            $table->foreignId('vendor_billing_document_id')
                ->constrained('vendor_billing_documents', 'id', 'vps_document_fk')
                ->cascadeOnDelete();

            $table->string('file_path');
            $table->string('original_filename')->nullable();

            // ── What the slip says ────────────────────────────────────────────
            // All nullable, all read off the document by the scan, none of them typed.
            // A slip the provider could not read still files — an unreadable document must
            // never be an unfileable one — and then every one of these is null.
            $table->decimal('paid_amount', 15, 2)->nullable();
            $table->date('paid_on')->nullable();
            $table->string('payment_reference')->nullable();
            $table->string('payment_method', 100)->nullable();
            // The invoice number printed ON THE SLIP, which is not necessarily the invoice
            // it was filed against. Kept apart from the document's own doc_number precisely
            // so the two can be compared and a mis-filed slip flagged.
            $table->string('invoice_reference')->nullable();
            $table->string('currency', 3)->default('MYR');

            // ── The reading (HasDocumentInsight) ──────────────────────────────
            $table->string('ai_status', 20)->nullable();
            $table->text('ai_summary')->nullable();
            $table->json('ai_key_points')->nullable();
            $table->longText('ai_text')->nullable();
            $table->timestamp('ai_at')->nullable();
            $table->timestamp('ai_summary_edited_at')->nullable();
            $table->foreignId('ai_summary_edited_by')->nullable()
                ->constrained('users', 'id', 'vps_summary_editor_fk')->nullOnDelete();
            // Payer and payee — who paid whom. Same column and same helper as every other
            // vendor document, so the "Companies Involved" wording means one thing on the
            // whole profile.
            $table->json('companies_involved')->nullable();

            $table->foreignId('uploaded_by')->nullable()
                ->constrained('users', 'id', 'vps_uploader_fk')->nullOnDelete();

            $table->timestamps();

            // The guarantee, in the schema. See the class docblock.
            $table->unique('vendor_billing_document_id', 'vps_document_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_payment_slips');
    }
};
