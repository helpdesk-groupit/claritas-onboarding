<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Move 'role' from personal_details to work_details
        Schema::table('personal_details', function (Blueprint $table) {
            $table->dropColumn('role');
        });

        Schema::table('work_details', function (Blueprint $table) {
            $table->enum('role', ['manager', 'senior_executive', 'executive_associate', 'director_hod', 'it_admin', 'others'])->nullable()->after('google_id');
        });

        // 2. Expand asset_inventories with full 5-section asset form
        // First drop old status column to re-add with expanded enum
        Schema::table('asset_inventories', function (Blueprint $table) {
            $table->dropColumn('status');
        });

        Schema::table('asset_inventories', function (Blueprint $table) {
            // Updated status with new values
            $table->enum('status', ['available', 'assigned', 'under_maintenance', 'retired', 'unavailable'])->default('available')->after('serial_number');

            // Section B – Specification
            $table->string('processor')->nullable()->after('notes');
            $table->string('ram_size')->nullable()->after('processor');
            $table->string('storage')->nullable()->after('ram_size');
            $table->string('operating_system')->nullable()->after('storage');
            $table->string('screen_size')->nullable()->after('operating_system');
            $table->text('spec_others')->nullable()->after('screen_size');

            // Section C – Procurement
            $table->date('purchase_date')->nullable()->after('spec_others');
            $table->string('purchase_vendor')->nullable()->after('purchase_date');
            $table->decimal('purchase_cost', 10, 2)->nullable()->after('purchase_vendor');
            $table->date('warranty_expiry_date')->nullable()->after('purchase_cost');
            $table->string('invoice_document')->nullable()->after('warranty_expiry_date');

            // Section D – Assignment
            $table->unsignedBigInteger('assigned_employee_id')->nullable()->after('invoice_document');
            $table->date('asset_assigned_date')->nullable()->after('assigned_employee_id');
            $table->date('expected_return_date')->nullable()->after('asset_assigned_date');

            // Section E – Condition
            $table->enum('asset_condition', ['new', 'good', 'fair', 'damaged'])->default('new')->after('expected_return_date');
            $table->enum('maintenance_status', ['none', 'under_maintenance', 'repair_required'])->default('none')->after('asset_condition');
            $table->date('last_maintenance_date')->nullable()->after('maintenance_status');
            $table->string('asset_photo')->nullable()->after('last_maintenance_date');
        });

        // 3. Expand users role enum
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('role');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', [
                'hr_manager', 'hr_executive', 'hr_intern',
                'it_manager', 'it_executive', 'it_intern',
                'employee'
            ])->default('employee')->after('password');
        });
    }

    public function down(): void
    {
        // Reverse all changes
        Schema::table('work_details', function (Blueprint $table) {
            $table->dropColumn('role');
        });
        Schema::table('personal_details', function (Blueprint $table) {
            $table->enum('role', ['manager','senior_executive','executive_associate','director_hod','it_admin','others'])->nullable();
        });

        $dropCols = ['processor','ram_size','storage','operating_system','screen_size','spec_others',
            'purchase_date','purchase_vendor','purchase_cost','warranty_expiry_date','invoice_document',
            'assigned_employee_id','asset_assigned_date','expected_return_date',
            'asset_condition','maintenance_status','last_maintenance_date','asset_photo','status'];
        Schema::table('asset_inventories', function (Blueprint $table) use ($dropCols) {
            $table->dropColumn($dropCols);
        });
        Schema::table('asset_inventories', function (Blueprint $table) {
            $table->enum('status', ['available','unavailable'])->default('available');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('role');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', ['hr_manager','hr_executive','hr_intern','employee','it_admin'])->default('employee');
        });
    }
};
