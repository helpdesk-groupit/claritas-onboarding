<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tracks one "Export approved PDFs (ZIP)" request from HR → Claims.
 *
 * The batch used to render synchronously inside the web request, bounded by a wall-clock
 * budget + a hard claim-count cap so it never outran nginx's 60s proxy_read_timeout. That
 * meant a cycle with enough HR-approved claims (ordered newest-processed-first) silently
 * dropped its OLDEST approvals off the tail once the cap was hit — a real cycle with 79
 * approved claims against a 60-claim cap left 19 out, and because of the newest-first
 * ordering, what got left out skewed toward whatever was approved earliest in the cycle,
 * which read to the operator as "only what I approved today shows up".
 *
 * The batch now renders in a background job (BuildClaimZipExport, dispatched to the same
 * `database` queue that drains Email Workflow sweeps and Social Strategist generation), so
 * it is no longer bound by the request's lifetime and can always render every matching
 * claim. This table is the request/progress/result record the HR page polls, mirroring
 * SocialStrategyRun / EmailWorkflowConnection's "long job, poll a row" shape.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_claim_zip_exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('requested_by_id')->constrained('users')->cascadeOnDelete();

            // The filter the export was requested with — re-applied fresh by the job at run
            // time (not the controller's snapshot), so a claim approved after the click but
            // before the job runs is still included.
            $table->unsignedSmallInteger('year')->nullable();
            $table->unsignedTinyInteger('month')->nullable();
            $table->json('companies')->nullable();
            $table->json('employee_ids')->nullable();

            $table->string('status', 20)->default('queued'); // queued|running|ready|failed

            $table->unsignedInteger('total_matched')->nullable();
            $table->unsignedInteger('rendered_count')->default(0);

            $table->string('file_path')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();

            // Rare: only when a filter matches more than the sanity ceiling. Distinct from
            // failed_claims (a claim that matched but could not be rendered).
            $table->json('omitted_claims')->nullable();
            $table->json('failed_claims')->nullable();

            $table->text('error')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            $table->index(['requested_by_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_claim_zip_exports');
    }
};
