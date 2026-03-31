<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Change asset_condition from ENUM to string so it accepts:
        // good, not_good, under_maintenance (and any future values)
        DB::statement("ALTER TABLE asset_inventories MODIFY COLUMN asset_condition VARCHAR(50) NOT NULL DEFAULT 'good'");

        // Change maintenance_status from ENUM to string so it accepts:
        // pending, in_progress, done (and any future values)
        DB::statement("ALTER TABLE asset_inventories MODIFY COLUMN maintenance_status VARCHAR(50) NULL DEFAULT NULL");

        // Migrate existing data to new values
        DB::statement("UPDATE asset_inventories SET asset_condition = 'good' WHERE asset_condition IN ('new', 'good')");
        DB::statement("UPDATE asset_inventories SET asset_condition = 'under_maintenance' WHERE asset_condition = 'fair'");
        DB::statement("UPDATE asset_inventories SET asset_condition = 'not_good' WHERE asset_condition = 'damaged'");

        DB::statement("UPDATE asset_inventories SET maintenance_status = 'pending' WHERE maintenance_status IN ('none', 'under_maintenance', 'repair_required')");
        DB::statement("UPDATE asset_inventories SET maintenance_status = NULL WHERE asset_condition != 'under_maintenance'");
    }

    public function down(): void
    {
        // Revert back to original ENUMs (data loss possible for new values)
        DB::statement("ALTER TABLE asset_inventories MODIFY COLUMN asset_condition ENUM('new','good','fair','damaged') NOT NULL DEFAULT 'good'");
        DB::statement("ALTER TABLE asset_inventories MODIFY COLUMN maintenance_status ENUM('none','under_maintenance','repair_required') NULL DEFAULT NULL");
    }
};