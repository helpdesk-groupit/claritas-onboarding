<?php

namespace App\Models;

/**
 * Prices a Claude call from the config catalogue (config/claude.php → model_rates),
 * in USD per MILLION tokens. Not an Eloquent model — pricing lives in code, not a
 * DB table, because Anthropic exposes no pricing API to sync from and prices change
 * only a few times a year (edit the config and deploy).
 *
 * Cost is split into an INPUT half and an OUTPUT half because Anthropic bills them
 * at different rates and the usage report shows each separately. ClaudeUsageRecorder
 * materialises both onto the usage row at write time, so a historical month keeps the
 * cost it was recorded at even after a rate here changes.
 */
class ClaudeModelRate
{
    /**
     * Anthropic's standard multipliers on the INPUT rate for prompt caching:
     * a cache write costs 1.25x and a cache read 0.1x. No current call site uses
     * caching, but the response reports the counters, so price them correctly
     * rather than charging them as ordinary input.
     */
    public const CACHE_WRITE_MULTIPLIER = 1.25;

    public const CACHE_READ_MULTIPLIER = 0.1;

    /** The full catalogue: ['model' => ['label', 'input', 'output'], …]. */
    public static function catalogue(): array
    {
        return (array) config('claude.model_rates', []);
    }

    /** Model ids that have a rate on file. */
    public static function pricedModels(): array
    {
        return array_keys(self::catalogue());
    }

    /** The rate row for a model, or null when it isn't in the catalogue. */
    public static function forModel(string $model): ?array
    {
        return self::catalogue()[$model] ?? null;
    }

    /**
     * INPUT-side cost in USD: prompt tokens plus any cache traffic, priced at the
     * model's input rate. Returns 0.0 for a model with no rate on file (the usage
     * row is still written — tokens are the truth — and the report flags it).
     */
    public static function inputCostFor(string $model, int $input, int $cacheWrite = 0, int $cacheRead = 0): float
    {
        $rate = self::forModel($model);
        if (! $rate) {
            return 0.0;
        }

        $in = (float) $rate['input'];
        $tokens = $input * $in
            + $cacheWrite * $in * self::CACHE_WRITE_MULTIPLIER
            + $cacheRead * $in * self::CACHE_READ_MULTIPLIER;

        return round($tokens / 1_000_000, 6);
    }

    /** OUTPUT-side cost in USD: completion tokens at the model's output rate. */
    public static function outputCostFor(string $model, int $output): float
    {
        $rate = self::forModel($model);
        if (! $rate) {
            return 0.0;
        }

        return round($output * (float) $rate['output'] / 1_000_000, 6);
    }

    /** Total cost of one call — input half + output half. */
    public static function costFor(string $model, int $input, int $output, int $cacheWrite = 0, int $cacheRead = 0): float
    {
        return round(
            self::inputCostFor($model, $input, $cacheWrite, $cacheRead)
            + self::outputCostFor($model, $output),
            6
        );
    }
}
