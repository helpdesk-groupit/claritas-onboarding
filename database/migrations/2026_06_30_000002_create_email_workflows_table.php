<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Email Workflow automations (IT > Automation > Email Workflow).
 *
 * One row = one "DocFlow" pipeline:
 *   Email Source → Detection Rules → Storage Destination → Log Destination,
 * running on a capture + reconcile schedule.
 *
 * Rule/storage/log/schedule config fold into JSON columns following the
 * established Claritas pattern (cf. personal_details.invite_staging_json),
 * keeping the schema compact while the wizard persists each step.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_workflows', function (Blueprint $table) {
            $table->id();

            // App-layer tenant scoping.
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();

            $table->string('name');
            // 'active' | 'paused' | 'draft' | 'error'
            $table->string('status', 20)->default('draft');

            // Provider connections (nullable until wizard wires them).
            $table->foreignId('email_connection_id')->nullable()
                  ->constrained('email_workflow_connections')->nullOnDelete();
            $table->foreignId('storage_connection_id')->nullable()
                  ->constrained('email_workflow_connections')->nullOnDelete();
            $table->foreignId('log_connection_id')->nullable()
                  ->constrained('email_workflow_connections')->nullOnDelete();

            // Step-2 detection rules (subject/body/attachment/sender + combine logic).
            $table->json('rules_json')->nullable();
            // Step-4 storage config (folder ref, monthly subfolders, filename template).
            $table->json('storage_config_json')->nullable();
            // Step-5 log config (target ref, partition-by-month, column map, idempotency cols).
            $table->json('log_config_json')->nullable();

            // Step-6 schedule.
            $table->string('timezone', 64)->default('Asia/Kuala_Lumpur');
            $table->string('capture_cron', 60)->nullable();    // default daily 19:00 local
            $table->string('reconcile_cron', 60)->nullable();  // default daily 07:00 local
            $table->boolean('first_sweep_on_activate')->default(true);

            // Wizard progress: highest step the user has completed (1..6).
            $table->unsignedTinyInteger('wizard_step')->default(1);

            // Run telemetry (populated by Phase-2 capture/reconcile jobs).
            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('next_run_at')->nullable();
            $table->unsignedInteger('captured_count')->default(0);
            $table->text('last_error')->nullable();

            $table->timestamps();

            $table->index(['created_by', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_workflows');
    }
};
