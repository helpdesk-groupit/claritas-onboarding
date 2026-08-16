<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The result of the last "Ask AI to compare quotations" run on a cycle.
 *
 * Deliberately separate from `recommended_quotation_id` / `recommendation_note` — those are
 * IT's ACTUAL recommendation, written by submitForApproval() and the only thing management
 * ever see or act on. These columns are only what the AI suggested; the cycle page pre-fills
 * the Recommend form from them, but IT still has to submit for it to count as anything. Kept
 * on the batch (one comparison run per cycle) rather than on the quotation, since re-running
 * it after a new vendor's offer arrives simply overwrites the previous suggestion.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asset_decommission_batches', function (Blueprint $table) {
            $table->unsignedBigInteger('ai_recommended_quotation_id')->nullable()->after('submitted_for_approval_at');
            $table->text('ai_recommendation_note')->nullable()->after('ai_recommended_quotation_id');
            $table->timestamp('ai_recommended_at')->nullable()->after('ai_recommendation_note');
            // ok | empty | disabled | failed — why the panel shows what it shows when a
            // comparison did not produce a suggestion.
            $table->string('ai_compare_status')->nullable()->after('ai_recommended_at');

            $table->foreign('ai_recommended_quotation_id', 'adb_ai_recommended_quote_fk')
                ->references('id')->on('asset_decommission_quotations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('asset_decommission_batches', function (Blueprint $table) {
            $table->dropForeign('adb_ai_recommended_quote_fk');
            $table->dropColumn(['ai_recommended_quotation_id', 'ai_recommendation_note', 'ai_recommended_at', 'ai_compare_status']);
        });
    }
};
