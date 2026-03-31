<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offboardings', function (Blueprint $table) {
            if (!Schema::hasColumn('offboardings', 'employee_id')) {
                $table->unsignedBigInteger('employee_id')->nullable()->after('onboarding_id');
                $table->foreign('employee_id')->references('id')->on('employees')->onDelete('set null');
            }
            // Add employment_status to employees table for HR to mark resigned/terminated
            if (!Schema::hasColumn('employees', 'employment_status')) {
                // This is handled in the employees migration below
            }
        });

        Schema::table('employees', function (Blueprint $table) {
            if (!Schema::hasColumn('employees', 'employment_status')) {
                $table->enum('employment_status', ['active', 'resigned', 'terminated', 'contract_ended'])
                      ->default('active')
                      ->after('exit_date');
                $table->string('resignation_reason')->nullable()->after('employment_status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('offboardings', function (Blueprint $table) {
            $table->dropForeign(['employee_id']);
            $table->dropColumn('employee_id');
        });
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn(['employment_status', 'resignation_reason']);
        });
    }
};