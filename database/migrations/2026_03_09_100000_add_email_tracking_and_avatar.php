<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Track email sending on onboardings table
        Schema::table('onboardings', function (Blueprint $table) {
            $table->boolean('calendar_invite_sent')->default(false)->after('it_emails');
            $table->boolean('welcome_email_sent')->default(false)->after('calendar_invite_sent');
        });

        // 2. Profile picture on users table
        Schema::table('users', function (Blueprint $table) {
            $table->string('profile_picture')->nullable()->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('onboardings', function (Blueprint $table) {
            $table->dropColumn(['calendar_invite_sent', 'welcome_email_sent']);
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('profile_picture');
        });
    }
};
