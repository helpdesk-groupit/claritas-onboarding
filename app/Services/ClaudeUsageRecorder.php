<?php

namespace App\Services;

use App\Models\ClaudeApiKeyHistory;
use App\Models\ClaudeApiUsageLog;
use App\Models\ClaudeModelRate;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Captures the `usage` object Anthropic returns on every /v1/messages response and
 * writes it to claude_api_usage_logs, priced with the current ClaudeModelRate.
 *
 * FAILS OPEN, always. Accounting for a call must never break the call: a missing
 * table (pre-migration), a DB hiccup, or a response shape we didn't expect all end
 * in a swallowed warning. Nobody's receipt scan fails because bookkeeping did.
 */
class ClaudeUsageRecorder
{
    /**
     * Record one Anthropic call.
     *
     * @param  string  $feature  a ClaudeApiUsageLog::FEATURES key
     * @param  array|null  $usage  the response's `usage` object, as returned by $resp->json('usage')
     */
    public static function record(string $feature, string $model, ?array $usage, ?string $company = null): void
    {
        try {
            $input = (int) ($usage['input_tokens'] ?? 0);
            $output = (int) ($usage['output_tokens'] ?? 0);
            $cacheWrite = (int) ($usage['cache_creation_input_tokens'] ?? 0);
            $cacheRead = (int) ($usage['cache_read_input_tokens'] ?? 0);

            // A failed/refused call bills nothing and reports no usage — don't write a
            // zero row for it, or the "calls" count stops meaning "calls we paid for".
            if ($input + $output + $cacheWrite + $cacheRead === 0) {
                return;
            }

            // Split the cost at write time so the report can show the input and output
            // halves separately without re-deriving them (which would drift from the
            // stored total if a rate later changed). input half carries cache traffic.
            $inCost = ClaudeModelRate::inputCostFor($model, $input, $cacheWrite, $cacheRead);
            $outCost = ClaudeModelRate::outputCostFor($model, $output);

            ClaudeApiUsageLog::create([
                'feature' => $feature,
                'model' => $model,
                'provider' => 'anthropic',
                'input_tokens' => $input,
                'output_tokens' => $output,
                'cache_creation_input_tokens' => $cacheWrite,
                'cache_read_input_tokens' => $cacheRead,
                'input_cost_usd' => $inCost,
                'output_cost_usd' => $outCost,
                'cost_usd' => round($inCost + $outCost, 6),
                // Auth::id() is null on scheduled/CLI calls — that's a valid, nullable state.
                'user_id' => Auth::id(),
                'company' => $company,
                'claude_api_key_history_id' => self::currentKeyHistoryId(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Claude usage recording failed', [
                'feature' => $feature,
                'model' => $model,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Which key was active when this call was made — looked up here rather than
     * threaded through every call site. Guarded by its own try/catch, separate from
     * the outer one: a hiccup in this lookup (e.g. this table missing on a fresh
     * deploy) must never take down the primary token/cost write it sits beside.
     */
    private static function currentKeyHistoryId(): ?int
    {
        try {
            return ClaudeApiKeyHistory::current()?->id;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
