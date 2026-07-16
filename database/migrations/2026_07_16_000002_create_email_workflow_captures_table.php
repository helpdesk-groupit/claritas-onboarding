<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per captured attachment — the module's idempotency source of truth
 * and audit trail.
 *
 * Dedupe is enforced by the DB, not by application logic: a UNIQUE index on
 * (email_workflow_id, key_hash) makes a double-capture a constraint violation
 * rather than a race we have to reason about. `key_hash` is sha256 of
 * DetectionEngine::idempotencyKey() ("message_id|attachment_name") because the
 * raw key is unbounded (long Message-IDs + long filenames) and would blow the
 * InnoDB index-length limit.
 *
 * Status is a resumable state machine — see CaptureService:
 *   pending → stored (bytes in Drive) → logged (row in the Sheet)
 * A crash between steps leaves a pending/stored row that the next run resumes,
 * so we get at-least-once delivery without duplicate uploads.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_workflow_captures', function (Blueprint $table) {
            $table->id();

            $table->foreignId('email_workflow_id')
                ->constrained('email_workflows')
                ->cascadeOnDelete();

            // The run that first claimed this key. Kept for provenance; a resumed
            // capture stays attributed to the run that created it.
            $table->foreignId('email_workflow_run_id')->nullable()
                ->constrained('email_workflow_runs')
                ->nullOnDelete();

            $table->string('message_id', 255);
            $table->string('attachment_name', 500);

            // Readable key for debugging; key_hash is what the index enforces.
            $table->text('idempotency_key');
            $table->char('key_hash', 64);

            // pending | stored | logged | failed
            $table->string('status', 20)->default('pending');

            // Populated once the bytes land in storage.
            $table->string('stored_file_id', 255)->nullable();
            $table->text('stored_file_url')->nullable();
            $table->string('stored_file_name', 500)->nullable();

            // Best-effort extraction, surfaced for review.
            $table->decimal('amount', 15, 2)->nullable();
            $table->string('currency', 8)->nullable();
            $table->boolean('needs_review')->default(false);

            $table->text('error')->nullable();
            $table->timestamp('logged_at')->nullable();
            $table->timestamps();

            // The idempotency guarantee.
            $table->unique(['email_workflow_id', 'key_hash'], 'ewf_captures_workflow_key_unique');
            $table->index(['email_workflow_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_workflow_captures');
    }
};
