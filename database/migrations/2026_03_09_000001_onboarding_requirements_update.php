<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. onboardings: change hr_email + it_email to JSON for multi-select
        Schema::table('onboardings', function (Blueprint $table) {
            $table->json('hr_emails')->nullable()->after('hr_email');
            $table->json('it_emails')->nullable()->after('it_email');
        });

        // 2. personal_details: add preferred_name
        Schema::table('personal_details', function (Blueprint $table) {
            $table->string('preferred_name', 100)->nullable()->after('full_name');
        });

        // 3. work_details: add reporting_manager_email
        Schema::table('work_details', function (Blueprint $table) {
            $table->string('reporting_manager_email', 255)->nullable()->after('reporting_manager');
        });
    }

    public function down(): void
    {
        Schema::table('onboardings', function (Blueprint $table) {
            $table->dropColumn(['hr_emails', 'it_emails']);
        });
        Schema::table('personal_details', function (Blueprint $table) {
            $table->dropColumn('preferred_name');
        });
        Schema::table('work_details', function (Blueprint $table) {
            $table->dropColumn('reporting_manager_email');
        });
    }
};
