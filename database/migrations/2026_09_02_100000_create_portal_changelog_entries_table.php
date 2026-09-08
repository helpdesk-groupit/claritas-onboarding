<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Settings → Portal Changelog. A manually-recorded log of changes/fixes/features made to the
 * portal itself (superadmin only) — not an automatic audit trail of employee record edits, which
 * already have their own per-module logs (onboarding_edit_logs, employee_edit_logs, etc.).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portal_changelog_entries', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('type', 30)->default('other');       // feature | improvement | bug_fix | security | config_change | other
            $table->string('status', 30)->default('completed'); // planned | in_progress | completed | rolled_back
            $table->string('module_area')->nullable();

            // The account that logged the entry (audit fact — never rewritten by an edit).
            $table->foreignId('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            // The credited author (editable — "normally us, but not always us", e.g. crediting
            // an external contractor or AI-assisted change without needing a second account).
            $table->string('changed_by_name')->nullable();

            // When the change actually happened — distinct from created_at, so a past change can
            // be logged after the fact without misdating it.
            $table->timestamp('occurred_at');

            // Who last corrected this log entry itself, kept apart from changed_by_* above.
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('updated_by_name')->nullable();

            $table->timestamps();

            $table->index('occurred_at');
            $table->index('type');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_changelog_entries');
    }
};
