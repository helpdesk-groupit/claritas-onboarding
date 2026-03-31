<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offboardings', function (Blueprint $table) {
            // Track the 3 automated notification emails
            $table->string('notice_email_status')->default('pending')->after('aarf_status');   // 1-month notice
            $table->string('reminder_email_status')->default('pending')->after('notice_email_status'); // 3-day reminder
            $table->string('sendoff_email_status')->default('pending')->after('reminder_email_status'); // day-of sendoff

            // IT PIC assignment (mirrors onboarding pattern)
            $table->unsignedBigInteger('assigned_pic_user_id')->nullable()->after('sendoff_email_status');
            $table->foreign('assigned_pic_user_id')->references('id')->on('users')->onDelete('set null');

            // Personal email for sendoff (cc)
            $table->string('personal_email')->nullable()->after('company_email');
        });
    }

    public function down(): void
    {
        Schema::table('offboardings', function (Blueprint $table) {
            $table->dropForeign(['assigned_pic_user_id']);
            $table->dropColumn([
                'notice_email_status', 'reminder_email_status', 'sendoff_email_status',
                'assigned_pic_user_id', 'personal_email',
            ]);
        });
    }
};
