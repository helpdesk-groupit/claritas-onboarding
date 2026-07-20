<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Editable price list for the Claude models this system calls — USD per MILLION
 * tokens, matching how Anthropic publishes pricing. Maintained by a superadmin on
 * the Claude API page, so a price change (or a new model) needs no deploy.
 *
 * Rates are applied at CALL time and the resulting cost is stored on the usage row
 * (see ClaudeApiUsageLog). Editing a rate therefore re-prices future calls only —
 * a historical month keeps costing what it actually cost.
 */
class ClaudeModelRate extends Model
{
    /**
     * Anthropic's standard multipliers on the INPUT rate for prompt caching:
     * a cache write costs 1.25x and a cache read 0.1x. None of the current call
     * sites use caching, but the response reports the counters, so price them
     * correctly rather than silently charging them as ordinary input.
     */
    public const CACHE_WRITE_MULTIPLIER = 1.25;

    public const CACHE_READ_MULTIPLIER = 0.1;

    protected $fillable = ['model', 'label', 'input_per_mtok', 'output_per_mtok'];

    protected $casts = [
        'input_per_mtok' => 'decimal:4',
        'output_per_mtok' => 'decimal:4',
    ];

    /** Per-request memo, keyed by model id — a sweep over many usage rows shouldn't re-query. */
    protected static array $memo = [];

    public static function forModel(string $model): ?self
    {
        if (! array_key_exists($model, static::$memo)) {
            static::$memo[$model] = static::query()->where('model', $model)->first();
        }

        return static::$memo[$model];
    }

    /** Forget the memo — for tests and after a rate edit within the same request. */
    public static function flushMemo(): void
    {
        static::$memo = [];
    }

    /**
     * Cost in USD for one call's token counts. Returns 0.0 when no rate is on file
     * for the model — the usage row is still written (tokens are the truth), and the
     * report flags un-priced models so the superadmin knows to add a rate rather than
     * reading a silently-understated total.
     */
    public static function costFor(string $model, int $input, int $output, int $cacheWrite = 0, int $cacheRead = 0): float
    {
        $rate = static::forModel($model);
        if (! $rate) {
            return 0.0;
        }

        $in = (float) $rate->input_per_mtok;
        $out = (float) $rate->output_per_mtok;

        $tokens = $input * $in
            + $output * $out
            + $cacheWrite * $in * self::CACHE_WRITE_MULTIPLIER
            + $cacheRead * $in * self::CACHE_READ_MULTIPLIER;

        return round($tokens / 1_000_000, 6);
    }
}
