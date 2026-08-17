<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A labeled rotation ledger for the Claude API key — separate from
 * `claude_api_settings`'s single usable-key row, which stays the only thing any
 * read call site (OCR, vendor insight, strategist) ever looks at. Every save that
 * types a new key closes the current row (`ended_at`) and opens a new one; the raw
 * key itself is never stored here, only a masked hint — a superseded key's raw
 * value is never needed again once rotated out.
 *
 * `claude_api_usage_logs.claude_api_key_history_id` lets the usage/cost report
 * attribute spend to whichever key was active when each call was made.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('claude_api_key_histories', function (Blueprint $table) {
            $table->id();
            $table->string('label', 190)->nullable();
            $table->string('masked_key', 40);
            $table->foreignId('set_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable(); // null = the currently active key
            $table->timestamps();
            $table->index('ended_at');
        });

        Schema::table('claude_api_usage_logs', function (Blueprint $table) {
            $table->foreignId('claude_api_key_history_id')->nullable()->after('id')
                ->constrained('claude_api_key_histories')->nullOnDelete();
        });

        $this->backfillFromExistingSetting();
    }

    /**
     * This table has only ever held one key in its whole life (a strict singleton,
     * overwritten in place on every save until now), so backfilling is exact, not a
     * guess: every existing usage-log row really was made under the one key on
     * record, and "history row 1" is simply what it always was.
     */
    private function backfillFromExistingSetting(): void
    {
        $setting = DB::table('claude_api_settings')->first();
        if (! $setting || empty($setting->api_key)) {
            return;
        }

        try {
            $rawKey = Crypt::decryptString($setting->api_key);
        } catch (\Throwable $e) {
            // A corrupt/legacy value just skips backfill rather than aborting the migration.
            return;
        }

        $historyId = DB::table('claude_api_key_histories')->insertGetId([
            'label' => null,
            'masked_key' => 'sk-ant-…'.substr($rawKey, -4),
            'set_by' => $setting->updated_by,
            // The earliest bound actually on record, not a guess — there is only one
            // key row and it has always existed.
            'started_at' => $setting->created_at,
            'ended_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('claude_api_usage_logs')
            ->whereNull('claude_api_key_history_id')
            ->update(['claude_api_key_history_id' => $historyId]);
    }

    public function down(): void
    {
        Schema::table('claude_api_usage_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('claude_api_key_history_id');
        });

        Schema::dropIfExists('claude_api_key_histories');
    }
};
