<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A short, human-readable summary of one quotation revision, shown on the Vendor Quotations
 * table alongside the offer amount — the e-waste-quotation equivalent of the Contracts/Billing
 * tabs' ai_summary, but purpose-built rather than the HasDocumentInsight trait for the same
 * reason ai_status/ai_transcript were (see 2026_08_16_090000's docblock): no Q&A assistant, no
 * summary-edit provenance, no companies_involved here.
 *
 * Written by the SAME vision call as ai_transcript (EwasteQuotationComparisonService::
 * transcribe()) rather than a second call — a one-or-two-sentence summary alongside a full
 * transcription is a small addition to that reply, not the kind of multi-field structured
 * extraction VendorDocumentInsightService keeps in its own separate text call.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asset_decommission_quotations', function (Blueprint $table) {
            $table->text('ai_summary')->nullable()->after('ai_transcript');
        });
    }

    public function down(): void
    {
        Schema::table('asset_decommission_quotations', function (Blueprint $table) {
            $table->dropColumn('ai_summary');
        });
    }
};
