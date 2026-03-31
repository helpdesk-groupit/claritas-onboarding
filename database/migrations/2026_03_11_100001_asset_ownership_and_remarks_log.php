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
            // Section C — Ownership
            $table->enum('ownership_type', ['company', 'rental'])->default('company')->after('invoice_document');

            // Rental-specific fields (only populated when ownership_type = 'rental')
            $table->string('rental_vendor')->nullable()->after('ownership_type');
            $table->string('rental_vendor_contact')->nullable()->after('rental_vendor');
            $table->decimal('rental_cost_per_month', 10, 2)->nullable()->after('rental_vendor_contact');
            $table->date('rental_start_date')->nullable()->after('rental_cost_per_month');
            $table->date('rental_end_date')->nullable()->after('rental_start_date');
            $table->string('rental_contract_reference')->nullable()->after('rental_end_date');

            // Remarks as a proper audit log (TEXT replaces the old nullable text)
            // remarks column already exists — just ensure it's text (no change needed structurally)
        });

        // Fix orphaned unavailable assets: if an asset is marked unavailable but has
        // no active (non-returned) assignment row, reset it to available.
        DB::statement("
            UPDATE asset_inventories ai
            LEFT JOIN asset_assignments aa
                ON aa.asset_inventory_id = ai.id
               AND aa.status = 'assigned'
            SET ai.status = 'available',
                ai.assigned_employee_id = NULL
            WHERE ai.status = 'unavailable'
              AND aa.id IS NULL
        ");
    }

    public function down(): void
    {
        Schema::table('asset_inventories', function (Blueprint $table) {
            $table->dropColumn([
                'ownership_type',
                'rental_vendor',
                'rental_vendor_contact',
                'rental_cost_per_month',
                'rental_start_date',
                'rental_end_date',
                'rental_contract_reference',
            ]);
        });
    }
};