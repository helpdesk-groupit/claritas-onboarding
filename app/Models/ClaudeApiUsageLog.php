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
     *
     * A RETIRED key stays here forever. This table is a spend ledger: rows written before
     * a feature was removed are still real money, and dropping the key would degrade them
     * to a raw slug on every historical report. Nothing writes the three vendor `*_scan`
     * keys below any more — the per-field OCR they billed was removed on 2026-08-11.
     */
    public const FEATURES = [
        'claim_receipt_scan' => 'eClaim — Receipt / Document Scan',
        'claim_item_verify' => 'eClaim — Reviewer Verification',
        'ewaste_quotation_scan' => 'Asset Decommissioning — Quotation Scan',
        'ewaste_receipt_scan' => 'Asset Decommissioning — Receipt Scan',
        // The two-call AI comparison (added 2026-08-16): transcribe() reads one document,
        // compare() reasons over every vendor's transcript at once. Billed apart for the
        // same reason the vendor document summary/detail passes are — two calls, two costs.
        'ewaste_quotation_transcribe' => 'Asset Decommissioning — Quotation Transcription',
        'ewaste_quotation_compare' => 'Asset Decommissioning — AI Quotation Comparison',
        // Retired 2026-08-11 (field OCR removed) — kept so past rows keep their names.
        'vendor_contract_scan' => 'Vendor Management — Contract Scan',
        'vendor_quotation_scan' => 'Vendor Management — Quotation Scan',
        'vendor_invoice_scan' => 'Vendor Management — Invoice Scan',
        'vendor_document_summary' => 'Vendor Management — Document Summary',
        // The second, text-only pass over a transcript that reads the parties and the
        // record fields. Billed apart from the summary because it is a separate call with
        // its own cost, and because "what did the reading of a document cost us" is only
        // answerable if both halves are visible.
        'vendor_document_fields' => 'Vendor Management — Document Details',
        'vendor_document_chat' => 'Vendor Management — Document Q&A',
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
        'ewaste_quotation_scan' => 'Asset Decommissioning',
        'ewaste_receipt_scan' => 'Asset Decommissioning',
        'ewaste_quotation_transcribe' => 'Asset Decommissioning',
        'ewaste_quotation_compare' => 'Asset Decommissioning',
        'vendor_contract_scan' => 'Vendor Management',
        'vendor_quotation_scan' => 'Vendor Management',
        'vendor_invoice_scan' => 'Vendor Management',
        'vendor_document_summary' => 'Vendor Management',
        'vendor_document_fields' => 'Vendor Management',
        'vendor_document_chat' => 'Vendor Management',
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
        'Asset Decommissioning' => '#0f766e',  // slot 5 — teal (matches the ewx- recycle chip)
        'Vendor Management' => '#b45309',      // slot 6 — amber (matches the vnd- vendor accent)
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
