<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {

        // 1. Add status tracking columns to offboardings
        Schema::table('offboardings', function (Blueprint $table) {
            $table->string('full_name')->nullable()->after('onboarding_id');
            $table->string('company')->nullable()->after('full_name');
            $table->string('department')->nullable()->after('company');
            $table->string('designation')->nullable()->after('department');
            $table->string('company_email')->nullable()->after('designation');
            $table->enum('calendar_reminder_status', ['pending','sent','failed'])->default('pending')->after('remarks');
            $table->enum('exiting_email_status', ['pending','sent','failed'])->default('pending')->after('calendar_reminder_status');
            $table->enum('aarf_status', ['pending','in_progress','done'])->default('pending')->after('exiting_email_status');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn([
                'full_name','official_document_id','date_of_birth','sex','marital_status',
                'religion','race','residential_address','personal_contact_number','personal_email',
                'bank_account_number','designation','department','company','office_location',
                'reporting_manager','company_email','start_date','exit_date','employment_type',
                'work_role','google_id','aarf_file_path',
            ]);
        });
        Schema::table('offboardings', function (Blueprint $table) {
            $table->dropColumn([
                'full_name','company','department','designation','company_email',
                'calendar_reminder_status','exiting_email_status','aarf_status',
            ]);
        });
    }
};
