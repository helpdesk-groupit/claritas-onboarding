<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5 — two decisions on a cycle, and management's is the one that moves it.
 *
 * Finance and management are notified together and either may decide first, but ONLY
 * management's approval advances the cycle. Finance's position is recorded and shown
 * alongside; it never releases assets to a vendor on its own, which is what keeps a later
 * management rejection able to stop something that has not happened yet.
 *
 * `recommended_quotation_id` is IT's proposal (normally the best price, with a reason);
 * `selected_quotation_id` is what management actually authorised, which may be a different
 * vendor's offer. Both are kept because "what we suggested" and "what was approved" are
 * different facts, and the gap between them is exactly what the final report has to show.
 *
 * Legacy cycles: `management_status` is backfilled from the Finance decision so a cycle
 * decided under the old single-approval rule still reads as decided, rather than reappearing
 * in every approver's queue as though it had never been looked at. `management_reviewed_by`
 * stays null — nobody in management actually signed those, and inventing an approver would
 * fabricate an audit trail.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asset_decommission_batches', function (Blueprint $table) {
            $table->string('management_status')->nullable()->after('finance_remarks'); // pending | approved | rejected
            $table->unsignedBigInteger('management_reviewed_by')->nullable()->after('management_status');
            $table->timestamp('management_reviewed_at')->nullable()->after('management_reviewed_by');
            $table->text('management_remarks')->nullable()->after('management_reviewed_at');

            $table->unsignedBigInteger('recommended_quotation_id')->nullable()->after('management_remarks');
            $table->text('recommendation_note')->nullable()->after('recommended_quotation_id');
            $table->unsignedBigInteger('selected_quotation_id')->nullable()->after('recommendation_note');
            $table->timestamp('submitted_for_approval_at')->nullable()->after('selected_quotation_id');

            $table->index('management_status', 'adb_management_status_idx');
            $table->foreign('management_reviewed_by', 'adb_mgmt_reviewer_fk')->references('id')->on('users')->nullOnDelete();
            $table->foreign('recommended_quotation_id', 'adb_recommended_quote_fk')->references('id')->on('asset_decommission_quotations')->nullOnDelete();
            $table->foreign('selected_quotation_id', 'adb_selected_quote_fk')->references('id')->on('asset_decommission_quotations')->nullOnDelete();
        });

        // A cycle already decided under the old rule keeps reading as decided.
        DB::table('asset_decommission_batches')
            ->whereIn('finance_status', ['approved', 'rejected'])
            ->update(['management_status' => DB::raw('finance_status')]);

        // Point the selection at the quotation those cycles were actually settled on, so the
        // report and the money tile keep resolving to the same document they always did.
        DB::statement('
            UPDATE asset_decommission_batches b
            JOIN asset_decommission_quotations q
              ON q.asset_decommission_batch_id = b.id
             AND q.revision = (
                 SELECT MAX(revision) FROM asset_decommission_quotations
                 WHERE asset_decommission_batch_id = b.id
             )
            SET b.selected_quotation_id = q.id
            WHERE b.finance_status = "approved"
        ');
    }

    public function down(): void
    {
        Schema::table('asset_decommission_batches', function (Blueprint $table) {
            $table->dropForeign('adb_mgmt_reviewer_fk');
            $table->dropForeign('adb_recommended_quote_fk');
            $table->dropForeign('adb_selected_quote_fk');
            $table->dropIndex('adb_management_status_idx');
            $table->dropColumn([
                'management_status', 'management_reviewed_by', 'management_reviewed_at', 'management_remarks',
                'recommended_quotation_id', 'recommendation_note', 'selected_quotation_id', 'submitted_for_approval_at',
            ]);
        });
    }
};
