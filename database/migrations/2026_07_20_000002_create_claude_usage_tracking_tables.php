<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Token-usage + spend tracking for the superadmin "Claude API" page.
 *
 * Every Anthropic call already returns `usage.input_tokens` / `usage.output_tokens`
 * in its response body — we simply stopped throwing it away. `claude_api_usage_logs`
 * is one row per billed call, stamped with the FEATURE that made it (eClaim receipt
 * scan, accounting invoice scan, …) so the report can break spend down by month and
 * by feature.
 *
 * `cost_usd` is materialised at write time from `claude_model_rates` rather than
 * derived on read: Anthropic changes its prices, and a historical month must keep
 * costing what it actually cost. Editing a rate therefore affects future calls only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('claude_model_rates', function (Blueprint $table) {
            $table->id();
            $table->string('model', 60)->unique();
            $table->string('label', 120)->nullable();
            // USD per MILLION tokens, matching how Anthropic publishes its pricing.
            $table->decimal('input_per_mtok', 10, 4)->default(0);
            $table->decimal('output_per_mtok', 10, 4)->default(0);
            $table->timestamps();
        });

        Schema::create('claude_api_usage_logs', function (Blueprint $table) {
            $table->id();
            $table->string('feature', 40);                 // ClaudeApiUsageLog::FEATURES key
            $table->string('model', 60);
            $table->string('provider', 20)->default('anthropic');
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('cache_creation_input_tokens')->default(0);
            $table->unsignedInteger('cache_read_input_tokens')->default(0);
            // 6dp: a Haiku receipt scan costs a small fraction of a cent — rounding to
            // 2dp would store 0.00 for most rows and the monthly total would be junk.
            $table->decimal('cost_usd', 12, 6)->default(0);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('company')->nullable();
            $table->timestamps();

            $table->index(['created_at', 'feature']);
        });

        Schema::table('claude_api_settings', function (Blueprint $table) {
            $table->decimal('usd_myr_rate', 8, 4)->default(4.7000)->after('enabled');
        });

        // Seed the models offered on the settings page with their list prices.
        // Sonnet 5 carries introductory pricing ($2/$10) through 2026-08-31 — seeded at
        // the intro rate because that is what is billed today; the page lets a superadmin
        // move it to $3/$15 when the intro period ends.
        $now = now();
        DB::table('claude_model_rates')->insert([
            ['model' => 'claude-haiku-4-5', 'label' => 'Claude Haiku 4.5', 'input_per_mtok' => 1.00, 'output_per_mtok' => 5.00, 'created_at' => $now, 'updated_at' => $now],
            ['model' => 'claude-sonnet-5', 'label' => 'Claude Sonnet 5 (intro rate to 31-08-2026)', 'input_per_mtok' => 2.00, 'output_per_mtok' => 10.00, 'created_at' => $now, 'updated_at' => $now],
            ['model' => 'claude-opus-4-8', 'label' => 'Claude Opus 4.8', 'input_per_mtok' => 5.00, 'output_per_mtok' => 25.00, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::table('claude_api_settings', function (Blueprint $table) {
            $table->dropColumn('usd_myr_rate');
        });
        Schema::dropIfExists('claude_api_usage_logs');
        Schema::dropIfExists('claude_model_rates');
    }
};
