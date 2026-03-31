<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Convert status from ENUM to VARCHAR so it accepts:
        // available, unavailable, assigned (new simplified set)
        DB::statement("ALTER TABLE asset_inventories MODIFY COLUMN status VARCHAR(50) NOT NULL DEFAULT 'available'");

        // Normalise any legacy values to the new three-value set
        DB::statement("UPDATE asset_inventories SET status = 'assigned'   WHERE assigned_employee_id IS NOT NULL");
        DB::statement("UPDATE asset_inventories SET status = 'unavailable' WHERE status IN ('under_maintenance','retired') AND assigned_employee_id IS NULL");
        DB::statement("UPDATE asset_inventories SET status = 'available'   WHERE status NOT IN ('available','unavailable','assigned')");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE asset_inventories MODIFY COLUMN status ENUM('available','assigned','under_maintenance','retired','unavailable') NOT NULL DEFAULT 'available'");
    }
};