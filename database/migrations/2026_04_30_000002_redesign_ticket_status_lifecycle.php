<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Status lifecycle redesign:
 *   - Drop "Assigned" (consolidated into "In Progress" — fires the moment a PIC is assigned)
 *   - Drop "Archived" status (replaced by terminal "Resolved" + new "Closed";
 *     the "Archived" tab now filters by status IN [Resolved, Closed])
 *   - Add "Pending" (auto-set by cron when an Open ticket sits 24h+ without PIC)
 *   - Add "Closed" (manual close without a Resolved verdict)
 *
 * Existing data migration heuristics:
 *   - Assigned                                 → In Progress
 *   - Archived AND resolved_at IS NOT NULL    → Resolved (took the Resolved auto-archive path)
 *   - Archived AND resolved_at IS NULL        → Closed (was archived without resolution)
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Convert column to varchar so we can freely reclassify before re-tightening.
        DB::statement("ALTER TABLE tickets MODIFY status VARCHAR(20) NOT NULL DEFAULT 'Open'");

        // 2. Reclassify existing rows
        DB::table('tickets')->where('status', 'Assigned')->update(['status' => 'In Progress']);
        DB::table('tickets')->where('status', 'Archived')->whereNotNull('resolved_at')->update(['status' => 'Resolved']);
        DB::table('tickets')->where('status', 'Archived')->whereNull('resolved_at')->update(['status' => 'Closed']);

        // 3. (Optional) tighten back to enum with the new value set
        DB::statement("ALTER TABLE tickets MODIFY status ENUM('Open', 'In Progress', 'Pending', 'Resolved', 'Closed') NOT NULL DEFAULT 'Open'");
    }

    public function down(): void
    {
        // Reverse: collapse new statuses back to the legacy ones. Best-effort —
        // some nuance is lost (e.g., we can't tell which In Progress used to be Assigned).
        DB::statement("ALTER TABLE tickets MODIFY status VARCHAR(20) NOT NULL DEFAULT 'Open'");
        DB::table('tickets')->where('status', 'Pending')->update(['status' => 'Open']);
        DB::table('tickets')->where('status', 'Resolved')->update(['status' => 'Archived']);
        DB::table('tickets')->where('status', 'Closed')->update(['status' => 'Archived']);
        DB::statement("ALTER TABLE tickets MODIFY status ENUM('Open', 'Assigned', 'In Progress', 'Resolved', 'Archived') NOT NULL DEFAULT 'Open'");
    }
};
