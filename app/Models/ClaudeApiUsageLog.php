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
        'strategist_gap_check' => 'Social Strategist — Gap Check',
        'strategist_market' => 'Social Strategist — Market Intelligence',
        'strategist_competitor' => 'Social Strategist — Competitor & Leverage',
        'strategist_compliance' => 'Social Strategist — Compliance Matrix',
        'strategist_strategy' => 'Social Strategist — Strategy',
        'strategist_measure' => 'Social Strategist — Roadmap & Measurement',
        'strategist_handoff' => 'Social Strategist — Executive Summary',
        'api_key_test' => 'Claude API — Key Test',
    ];

    protected $fillable = [
        'feature', 'model', 'provider',
        'input_tokens', 'output_tokens',
        'cache_creation_input_tokens', 'cache_read_input_tokens',
        'input_cost_usd', 'output_cost_usd', 'cost_usd', 'user_id', 'company',
    ];

    protected $casts = [
        'input_tokens' => 'integer',
        'output_tokens' => 'integer',
        'cache_creation_input_tokens' => 'integer',
        'cache_read_input_tokens' => 'integer',
        'input_cost_usd' => 'decimal:6',
        'output_cost_usd' => 'decimal:6',
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
        'strategist_gap_check' => 'Social Strategist',
        'strategist_market' => 'Social Strategist',
        'strategist_competitor' => 'Social Strategist',
        'strategist_compliance' => 'Social Strategist',
        'strategist_strategy' => 'Social Strategist',
        'strategist_measure' => 'Social Strategist',
        'strategist_handoff' => 'Social Strategist',
        'api_key_test' => 'System / Admin',
    ];

    public static function featureLabel(?string $feature): string
    {
        return self::FEATURES[$feature] ?? ($feature ?: 'Unknown');
    }

    /**
     * Chart colour per MODULE, keyed by the module itself — never by its position in
     * the sorted list. A reader who learns "eClaim is blue" must keep that after a
     * filter changes which module ranks first; colouring by rank would repaint the
     * survivors and quietly lie. Slots 1-3 of a CVD-validated categorical palette.
     */
    public const MODULE_COLORS = [
        'eClaim (Receipt OCR)' => '#2a78d6',   // slot 1 — blue
        'Accounting (AI)' => '#008300',        // slot 2 — green
        'System / Admin' => '#e87ba4',         // slot 3 — magenta
        'Social Strategist' => '#7c3aed',      // slot 4 — violet (matches the module accent)
        'Other' => '#898781',                  // muted grey — the catch-all, never a real slot
    ];

    public static function moduleLabel(?string $feature): string
    {
        return self::MODULES[$feature] ?? 'Other';
    }

    public static function moduleColor(?string $module): string
    {
        return self::MODULE_COLORS[$module] ?? self::MODULE_COLORS['Other'];
    }

    /** Total tokens billed on this call (all four counters). */
    public function totalTokens(): int
    {
        return $this->input_tokens + $this->output_tokens
            + $this->cache_creation_input_tokens + $this->cache_read_input_tokens;
    }
}
