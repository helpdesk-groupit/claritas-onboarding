<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add manager_id FK to employees for proper reporting structure
        Schema::table('employees', function (Blueprint $table) {
            $table->unsignedBigInteger('manager_id')->nullable()->after('reporting_manager');
            $table->string('reporting_manager_email')->nullable()->after('manager_id');

            $table->foreign('manager_id')->references('id')->on('employees')->nullOnDelete();
        });

        // Add manager-level approval tracking to leave applications
        Schema::table('leave_applications', function (Blueprint $table) {
            $table->unsignedBigInteger('manager_approved_by')->nullable()->after('approved_by');
            $table->datetime('manager_approved_at')->nullable()->after('manager_approved_by');
            $table->string('manager_remarks', 500)->nullable()->after('manager_approved_at');
            $table->enum('manager_status', ['pending', 'approved', 'rejected'])->default('pending')->after('manager_remarks');
        });
    }

    public function down(): void
    {
        Schema::table('leave_applications', function (Blueprint $table) {
            $table->dropColumn(['manager_approved_by', 'manager_approved_at', 'manager_remarks', 'manager_status']);
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->dropForeign(['manager_id']);
            $table->dropColumn(['manager_id', 'reporting_manager_email']);
        });
    }
};
