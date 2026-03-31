<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offboardings', function (Blueprint $table) {
            // Reporting manager email so we can CC them on notifications
            $table->string('reporting_manager_email')->nullable()->after('company_email');
            // 1-week reminder (between 1-month notice and 3-day reminder)
            $table->string('week_reminder_email_status')->default('pending')->after('reminder_email_status');
        });
    }

    public function down(): void
    {
        Schema::table('offboardings', function (Blueprint $table) {
            $table->dropColumn(['reporting_manager_email', 'week_reminder_email_status']);
        });
    }
};