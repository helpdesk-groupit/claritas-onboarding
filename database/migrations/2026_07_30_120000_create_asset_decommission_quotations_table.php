<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One row per QUOTATION REVISION in an e-waste cycle, each carrying its own Finance decision.
 *
 * Why: the cycle's quotation lived in a single set of columns on asset_decommission_batches,
 * so a re-quote after a Finance rejection OVERWROTE it — `quotation_path`/`amount`/`uploaded_*`
 * were replaced and `finance_status`/`finance_reviewed_*`/`finance_remarks` were reset to null
 * on purpose ("clear any prior rejection remarks on re-quote"). The rejection therefore
 * vanished: the cycle's log read as though the accepted quotation was the only one ever sent,
 * with no record that Finance had refused an earlier offer or why. That is the one part of the
 * cycle a financial audit most needs — the money changed between the two documents.
 *
 * The batch columns are KEPT as a materialised cache of the CURRENT revision (the same pattern
 * the company re-attribution uses): they are read by the Finance pending-quotation query, the
 * report renderer, the report PDF, both mailables and the reports listing, and `finance_status`
 * is an indexed filter key. AssetDecommissionBatch::addQuotationRevision() /
 * recordFinanceDecision() / setQuotationAmount() write the row and the cache together so the
 * two can never disagree.
 *
 * Backfill: every existing e-waste batch that has a quotation on file becomes revision 1,
 * copied verbatim from those columns. Rejections that were ALREADY overwritten before this
 * table existed cannot be recovered — the path, amount, actor and reason are gone from the
 * database — and are deliberately not invented; history starts being recorded from here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_decommission_quotations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('asset_decommission_batch_id');
            $table->unsignedInteger('revision');          // 1-based, per batch
            $table->string('path');
            $table->decimal('amount', 12, 2)->nullable(); // what the vendor offers to PAY US
            $table->timestamp('uploaded_at')->nullable();
            $table->unsignedBigInteger('uploaded_by')->nullable();

            // The Finance decision on THIS revision. Null = never submitted for review.
            $table->string('finance_status')->nullable(); // pending | approved | rejected
            $table->unsignedBigInteger('finance_reviewed_by')->nullable();
            $table->timestamp('finance_reviewed_at')->nullable();
            $table->text('finance_remarks')->nullable();

            $table->timestamps();

            $table->unique(['asset_decommission_batch_id', 'revision'], 'adq_batch_revision_unique');
            $table->foreign('asset_decommission_batch_id', 'adq_batch_fk')
                ->references('id')->on('asset_decommission_batches')->cascadeOnDelete();
            $table->foreign('uploaded_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('finance_reviewed_by')->references('id')->on('users')->nullOnDelete();
        });

        // Backfill revision 1 from the existing cache columns — same data, relocated.
        $now = now();
        $rows = DB::table('asset_decommission_batches')
            ->where('type', 'e_waste')
            ->whereNotNull('quotation_path')
            ->get([
                'id', 'quotation_path', 'quotation_amount', 'quotation_uploaded_at', 'quotation_uploaded_by',
                'finance_status', 'finance_reviewed_by', 'finance_reviewed_at', 'finance_remarks',
            ]);

        foreach ($rows->chunk(200) as $chunk) {
            DB::table('asset_decommission_quotations')->insert($chunk->map(fn ($b) => [
                'asset_decommission_batch_id' => $b->id,
                'revision' => 1,
                'path' => $b->quotation_path,
                'amount' => $b->quotation_amount,
                'uploaded_at' => $b->quotation_uploaded_at,
                'uploaded_by' => $b->quotation_uploaded_by,
                'finance_status' => $b->finance_status,
                'finance_reviewed_by' => $b->finance_reviewed_by,
                'finance_reviewed_at' => $b->finance_reviewed_at,
                'finance_remarks' => $b->finance_remarks,
                'created_at' => $b->quotation_uploaded_at ?: $now,
                'updated_at' => $now,
            ])->all());
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_decommission_quotations');
    }
};
