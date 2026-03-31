<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Reset any asset marked unavailable that has:
        // (a) no active assignment row, OR
        // (b) assigned_employee_id is null
        // This fixes assets orphaned when employees/onboardings were deleted.
        DB::statement("
            UPDATE asset_inventories ai
            LEFT JOIN asset_assignments aa
                ON aa.asset_inventory_id = ai.id
               AND aa.status = 'assigned'
            SET ai.status       = 'available',
                ai.assigned_employee_id = NULL,
                ai.asset_assigned_date  = NULL,
                ai.expected_return_date = NULL
            WHERE ai.status = 'unavailable'
              AND aa.id IS NULL
        ");

        $fixed = DB::select("SELECT COUNT(*) as cnt FROM asset_inventories WHERE status = 'unavailable' AND assigned_employee_id IS NULL");
        // Any remaining unavailable with no employee — also reset
        DB::statement("
            UPDATE asset_inventories
            SET status = 'available',
                asset_assigned_date = NULL,
                expected_return_date = NULL
            WHERE status = 'unavailable'
              AND assigned_employee_id IS NULL
        ");
    }

    public function down(): void
    {
        // Irreversible data fix — no rollback
    }
};