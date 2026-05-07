<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            // Throttle column for the stale-ticket reminder cron — prevents
            // hourly cron runs from spamming the same recipients.
            $table->timestamp('last_reminder_sent_at')->nullable()->after('resolved_at');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn('last_reminder_sent_at');
        });
    }
};
