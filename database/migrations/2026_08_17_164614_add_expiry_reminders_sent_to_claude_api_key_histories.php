<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tracks which of `claude.key_expiry.remind_before_days` thresholds have already
 * been emailed for THIS key (a JSON array of day-numbers, e.g. [7,3]) so the daily
 * reminder command never double-sends. Rotating the key opens a brand-new history
 * row (see ClaudeApiKeyHistory::rotate()), so this always starts empty on a fresh
 * key without any special-casing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('claude_api_key_histories', function (Blueprint $table) {
            $table->json('expiry_reminders_sent')->nullable()->after('ended_at');
        });
    }

    public function down(): void
    {
        Schema::table('claude_api_key_histories', function (Blueprint $table) {
            $table->dropColumn('expiry_reminders_sent');
        });
    }
};
