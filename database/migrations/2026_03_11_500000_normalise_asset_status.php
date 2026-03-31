<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Expand enum to include 'assigned' if not already present
        //    (MySQL requires re-declaring all values)
        DB::statement("ALTER TABLE asset_inventories MODIFY COLUMN status ENUM('available','assigned','under_maintenance','retired') NOT NULL DEFAULT 'available'");

        // 2. Any asset with assigned_employee_id set → status = 'assigned'
        DB::statement("UPDATE asset_inventories SET status = 'assigned' WHERE assigned_employee_id IS NOT NULL AND status != 'under_maintenance' AND status != 'retired'");

        // 3. Any asset with no assigned_employee_id but status was 'unavailable' → reset to 'available'
        DB::statement("UPDATE asset_inventories SET status = 'available' WHERE assigned_employee_id IS NULL AND status NOT IN ('under_maintenance','retired','assigned')");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE asset_inventories MODIFY COLUMN status ENUM('available','assigned','unavailable','under_maintenance','retired') NOT NULL DEFAULT 'available'");
    }
};