<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asset_inventories', function (Blueprint $table) {
            // Add remarks audit log column (was missing from all previous migrations)
            $table->text('remarks')->nullable()->after('asset_photo');

            // Add company_name for company-owned assets (Section C)
            $table->string('company_name')->nullable()->after('invoice_document');
        });

        // Fix orphaned unavailable assets:
        // Any asset marked unavailable with no active assignment row → reset to available
        DB::statement("
            UPDATE asset_inventories ai
            LEFT JOIN asset_assignments aa
                ON aa.asset_inventory_id = ai.id
               AND aa.status = 'assigned'
            SET ai.status               = 'available',
                ai.assigned_employee_id = NULL,
                ai.asset_assigned_date  = NULL,
                ai.expected_return_date = NULL
            WHERE ai.status = 'unavailable'
              AND aa.id IS NULL
        ");

        // Safety net: also reset any unavailable with no assigned employee
        DB::statement("
            UPDATE asset_inventories
            SET status               = 'available',
                asset_assigned_date  = NULL,
                expected_return_date = NULL
            WHERE status = 'unavailable'
              AND assigned_employee_id IS NULL
        ");
    }

    public function down(): void
    {
        Schema::table('asset_inventories', function (Blueprint $table) {
            $table->dropColumn(['remarks', 'company_name']);
        });
    }
};