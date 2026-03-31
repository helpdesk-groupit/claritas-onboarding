<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Make personal_details columns nullable for invite flow placeholder records
        Schema::table('personal_details', function (Blueprint $table) {
            $table->string('full_name')->nullable()->change();
            $table->string('official_document_id')->nullable()->change();
            $table->date('date_of_birth')->nullable()->change();
            $table->string('sex')->nullable()->change();
            $table->string('marital_status')->nullable()->change();
            $table->string('religion')->nullable()->change();
            $table->string('race')->nullable()->change();
            $table->text('residential_address')->nullable()->change();
            $table->string('personal_contact_number')->nullable()->change();
            $table->string('personal_email')->nullable()->change();
            $table->string('bank_account_number')->nullable()->change();
        });

        // Make work_details columns nullable for invite flow placeholder records
        Schema::table('work_details', function (Blueprint $table) {
            $table->string('designation')->nullable()->change();
            $table->string('employment_type')->nullable()->change();
            $table->date('start_date')->nullable()->change();
            $table->string('employee_status')->nullable()->change();
            $table->string('staff_status')->nullable()->change();
            $table->string('company')->nullable()->change();
            $table->string('office_location')->nullable()->change();
            $table->string('reporting_manager')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Only revert if you are sure no null values exist in these columns
        Schema::table('personal_details', function (Blueprint $table) {
            $table->string('full_name')->nullable(false)->change();
            $table->string('official_document_id')->nullable(false)->change();
        });
    }
};