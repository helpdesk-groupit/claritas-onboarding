<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two related changes to the Claude usage tracking:
 *
 * 1. Split each usage row's cost into an INPUT half and an OUTPUT half, so the report
 *    can show "X input tokens = $A, Y output tokens = $B" per feature. Existing rows
 *    are back-filled from the config catalogue (their stored total was computed from
 *    the same numbers, so the split re-derives cleanly).
 *
 * 2. Retire the editable pricing UI. Model rates now live in config/claude.php (there
 *    is no Anthropic pricing API to sync from, and prices change rarely), so the
 *    claude_model_rates table and the claude_api_settings.usd_myr_rate column — both
 *    only fed by the removed "Pricing" card — are dropped. down() restores them.
 */
return new class extends Migration
{
    /** Model rates as of this migration — inlined so back-fill never depends on config. */
    private const BACKFILL_RATES = [
        'claude-haiku-4-5' => ['input' => 1.00, 'output' => 5.00],
        'claude-sonnet-5' => ['input' => 2.00, 'output' => 10.00],
        'claude-opus-4-8' => ['input' => 5.00, 'output' => 25.00],
    ];

    public function up(): void
    {
        Schema::table('claude_api_usage_logs', function (Blueprint $table) {
            $table->decimal('input_cost_usd', 12, 6)->default(0)->after('cache_read_input_tokens');
            $table->decimal('output_cost_usd', 12, 6)->default(0)->after('input_cost_usd');
        });

        // Back-fill the split for rows written before this change. Rates are inlined
        // (not read from config) so the migration stays self-contained and correct even
        // if the app's config is cached from a build that predates config/claude.php.
        // These match the config catalogue at the time of writing; existing rows were all
        // priced from the same numbers, so the split re-derives their stored total exactly.
        $rates = self::BACKFILL_RATES;
        foreach (DB::table('claude_api_usage_logs')->get() as $row) {
            $rate = $rates[$row->model] ?? null;
            if (! $rate) {
                continue; // unpriced model — leave the split at 0, same as its total
            }
            $in = (float) $rate['input'];
            $out = (float) $rate['output'];
            $inCost = round(($row->input_tokens * $in
                + $row->cache_creation_input_tokens * $in * 1.25
                + $row->cache_read_input_tokens * $in * 0.1) / 1_000_000, 6);
            $outCost = round($row->output_tokens * $out / 1_000_000, 6);
            DB::table('claude_api_usage_logs')->where('id', $row->id)->update([
                'input_cost_usd' => $inCost,
                'output_cost_usd' => $outCost,
                'cost_usd' => round($inCost + $outCost, 6),
            ]);
        }

        Schema::table('claude_api_settings', function (Blueprint $table) {
            $table->dropColumn('usd_myr_rate');
        });

        Schema::dropIfExists('claude_model_rates');
    }

    public function down(): void
    {
        Schema::create('claude_model_rates', function (Blueprint $table) {
            $table->id();
            $table->string('model', 60)->unique();
            $table->string('label', 120)->nullable();
            $table->decimal('input_per_mtok', 10, 4)->default(0);
            $table->decimal('output_per_mtok', 10, 4)->default(0);
            $table->timestamps();
        });

        $now = now();
        foreach (self::BACKFILL_RATES as $model => $rate) {
            DB::table('claude_model_rates')->insert([
                'model' => $model,
                'input_per_mtok' => $rate['input'],
                'output_per_mtok' => $rate['output'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        Schema::table('claude_api_settings', function (Blueprint $table) {
            $table->decimal('usd_myr_rate', 8, 4)->default(4.7000)->after('enabled');
        });

        Schema::table('claude_api_usage_logs', function (Blueprint $table) {
            $table->dropColumn(['input_cost_usd', 'output_cost_usd']);
        });
    }
};
