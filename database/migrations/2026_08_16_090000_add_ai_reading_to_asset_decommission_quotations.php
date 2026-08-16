<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The AI reading of one quotation revision, used by EwasteQuotationComparisonService to
 * compare vendors on more than the RM figure alone.
 *
 * Deliberately NOT the HasDocumentInsight trait VendorContract/VendorBillingDocument use —
 * that trait drags in the vendor document Q&A assistant, summary-edit provenance and
 * companies_involved, none of which apply to an e-waste quotation. This is a lean,
 * purpose-built pair: a transcript and the status of reading it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asset_decommission_quotations', function (Blueprint $table) {
            // null = never read. pending is never stored here — the read is synchronous,
            // triggered by IT clicking "Ask AI to compare".
            $table->string('ai_status')->nullable()->after('finance_remarks');
            $table->longText('ai_transcript')->nullable()->after('ai_status');
            $table->timestamp('ai_read_at')->nullable()->after('ai_transcript');
        });
    }

    public function down(): void
    {
        Schema::table('asset_decommission_quotations', function (Blueprint $table) {
            $table->dropColumn(['ai_status', 'ai_transcript', 'ai_read_at']);
        });
    }
};
