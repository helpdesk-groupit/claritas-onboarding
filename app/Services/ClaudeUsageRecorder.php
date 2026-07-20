<?php

namespace App\Services;

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

            ClaudeApiUsageLog::create([
                'feature' => $feature,
                'model' => $model,
                'provider' => 'anthropic',
                'input_tokens' => $input,
                'output_tokens' => $output,
                'cache_creation_input_tokens' => $cacheWrite,
                'cache_read_input_tokens' => $cacheRead,
                'cost_usd' => ClaudeModelRate::costFor($model, $input, $output, $cacheWrite, $cacheRead),
                // Auth::id() is null on scheduled/CLI calls — that's a valid, nullable state.
                'user_id' => Auth::id(),
                'company' => $company,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Claude usage recording failed', [
                'feature' => $feature,
                'model' => $model,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
