<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hardens it_tasks against duplicate onboarding/offboarding task rows.
 *
 * Root cause: ItTaskController::assignPic()/assignOffboardingPic() used a
 * non-atomic delete-then-create with no DB transaction and no unique index, so
 * a double-submit / race on "Assign PIC" could insert two work_email (or
 * asset_preparation) rows for the same onboarding_id. The Task Management page
 * then showed the same task twice (e.g. one Pending + one Done).
 *
 * This migration:
 *   1. De-duplicates any existing rows BEFORE adding the unique index (else the
 *      index creation would fail on the very duplicates we're fixing). For each
 *      (scope, task_type) group it keeps the most-progressed row — done >
 *      in_progress > pending, then most-recently completed/created — and deletes
 *      the redundant ones, so no completed work is lost.
 *   2. Adds unique guards on (onboarding_id, task_type) and
 *      (offboarding_id, task_type). Both columns are nullable; MariaDB/MySQL
 *      allow multiple NULLs in a unique index, so the onboarding guard ignores
 *      offboarding rows (onboarding_id NULL) and vice-versa.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── 1. De-duplicate ────────────────────────────────────────────────
        foreach (['onboarding_id', 'offboarding_id'] as $col) {
            $groups = DB::table('it_tasks')
                ->select($col, 'task_type', DB::raw('COUNT(*) as cnt'))
                ->whereNotNull($col)
                ->groupBy($col, 'task_type')
                ->having('cnt', '>', 1)
                ->get();

            foreach ($groups as $g) {
                $keepId = DB::table('it_tasks')
                    ->where($col, $g->$col)
                    ->where('task_type', $g->task_type)
                    ->orderByRaw("FIELD(status,'done','in_progress','pending')")
                    ->orderByRaw('completed_at IS NULL')   // rows with a completed_at first
                    ->orderByDesc('completed_at')
                    ->orderByDesc('created_at')
                    ->orderByDesc('id')
                    ->value('id');

                DB::table('it_tasks')
                    ->where($col, $g->$col)
                    ->where('task_type', $g->task_type)
                    ->where('id', '!=', $keepId)
                    ->delete();
            }
        }

        // ── 2. Unique guards ────────────────────────────────────────────────
        Schema::table('it_tasks', function (Blueprint $table) {
            $table->unique(['onboarding_id', 'task_type'], 'it_tasks_onb_type_unique');
            $table->unique(['offboarding_id', 'task_type'], 'it_tasks_off_type_unique');
        });
    }

    public function down(): void
    {
        Schema::table('it_tasks', function (Blueprint $table) {
            $table->dropUnique('it_tasks_onb_type_unique');
            $table->dropUnique('it_tasks_off_type_unique');
        });
    }
};
