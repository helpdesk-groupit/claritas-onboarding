<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per billed Anthropic call: which FEATURE made it, which model, how many
 * tokens, and what it cost. Written by ClaudeUsageRecorder from the `usage` object
 * every Anthropic response already carries; read by the Claude API page's
 * "Usage & Cost" report (grouped by month x feature) and its PDF export.
 *
 * Only Anthropic calls are logged — a claim scanned through Gemini/OpenAI/Ollama
 * (the env-config fallback path) writes nothing here, by design.
 */
class ClaudeApiUsageLog extends Model
{
    /**
     * Every call site that can reach Anthropic, and how it reads on the report.
     * Add a key here when you add a call site, and pass it as the $feature argument
     * — an unmapped key still logs, and falls back to showing its raw slug.
     */
    public const FEATURES = [
        'claim_receipt_scan' => 'eClaim — Receipt / Document Scan',
        'claim_item_verify' => 'eClaim — Reviewer Verification',
        'accounting_invoice_scan' => 'Accounting — Invoice Scan',
        'accounting_ai_chat' => 'Accounting — AI Assistant',
        'api_key_test' => 'Claude API — Key Test',
    ];

    protected $fillable = [
        'feature', 'model', 'provider',
        'input_tokens', 'output_tokens',
        'cache_creation_input_tokens', 'cache_read_input_tokens',
        'cost_usd', 'user_id', 'company',
    ];

    protected $casts = [
        'input_tokens' => 'integer',
        'output_tokens' => 'integer',
        'cache_creation_input_tokens' => 'integer',
        'cache_read_input_tokens' => 'integer',
        'cost_usd' => 'decimal:6',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Which part of the system each feature belongs to. The report rolls spend up to
     * this level first ("what does eClaim cost us?") before breaking it into features,
     * because that is the question a budget owner actually asks.
     */
    public const MODULES = [
        'claim_receipt_scan' => 'eClaim (Receipt OCR)',
        'claim_item_verify' => 'eClaim (Receipt OCR)',
        'accounting_invoice_scan' => 'Accounting (AI)',
        'accounting_ai_chat' => 'Accounting (AI)',
        'api_key_test' => 'System / Admin',
    ];

    public static function featureLabel(?string $feature): string
    {
        return self::FEATURES[$feature] ?? ($feature ?: 'Unknown');
    }

    public static function moduleLabel(?string $feature): string
    {
        return self::MODULES[$feature] ?? 'Other';
    }

    /** Total tokens billed on this call (all four counters). */
    public function totalTokens(): int
    {
        return $this->input_tokens + $this->output_tokens
            + $this->cache_creation_input_tokens + $this->cache_read_input_tokens;
    }
}
