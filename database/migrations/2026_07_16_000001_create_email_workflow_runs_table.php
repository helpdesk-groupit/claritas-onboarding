<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per capture run (manual "Run now" or scheduled sweep).
 *
 * Gives the module its observability surface: what ran, when, how long, what
 * it scanned/captured/skipped/failed, and why it failed. The list page reads
 * the latest run per workflow.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_workflow_runs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('email_workflow_id')
                ->constrained('email_workflows')
                ->cascadeOnDelete();

            // Who/what started it. 'manual' carries the triggering user.
            $table->string('trigger', 20)->default('manual');   // manual | scheduled
            $table->foreignId('triggered_by')->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // running → success | partial | failed
            $table->string('status', 20)->default('running');

            $table->unsignedInteger('scanned_count')->default(0);
            $table->unsignedInteger('matched_count')->default(0);
            $table->unsignedInteger('captured_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);

            $table->text('error')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            // "latest run for this workflow" — the list page's hot query.
            $table->index(['email_workflow_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_workflow_runs');
    }
};
