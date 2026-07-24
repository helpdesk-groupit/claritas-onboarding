<?php

namespace App\Services;

use App\Models\Accounting\AccountingSetting;
use App\Models\ClaudeApiSetting;
use App\Models\SocialStrategy;
use App\Models\SocialStrategyFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * The AI brain of the Social Media AI Strategist — a server-side port of the
 * "Strategist OS" browser agent's Claude calls.
 *
 * Everything runs through Anthropic's /v1/messages using the same single-shot
 * Http pattern as AiAccountingService, plus the web_search server-tool (this is
 * the first use of Anthropic tools in the codebase). The anti-hallucination
 * DOCTRINE is sent as the system prompt on every call. Spend is recorded on the
 * Claude API usage report via ClaudeUsageRecorder.
 *
 * Fails LOUD to its callers (throws) so the generation job / controller can turn
 * a failure into a section error or a flash — but never leaks a raw key.
 */
class SocialMediaStrategistService
{
    /** Default when neither the Claude API page nor Accounting names a model. */
    public const DEFAULT_MODEL = 'claude-sonnet-5';

    private const MAX_RETRIES = 2;

    /** Cap web searches per section so a runaway tool loop can't burn budget. */
    private const MAX_SEARCHES = 6;

    /**
     * The anti-hallucination doctrine — sent as the `system` prompt on every
     * call. Ported verbatim from strategist-os-agent.html (lines 159-167). Do
     * not soften: it is the feature's entire value and mirrors the user's global
     * verification-first contract.
     */
    public const DOCTRINE = <<<'DOCTRINE'
You are Strategist OS — a senior cross-industry social media strategist agent implementing the "social-media-strategist" skill.
ABSOLUTE RULES (anti-hallucination doctrine):
1. TRUTH SOURCES ONLY: the client FACTBASE, wizard answers, gap-check answers, and (when the web_search tool is available) live search results. You may NEVER invent client facts, competitor facts, statistics, regulations, benchmarks or quotes.
2. If a needed fact is absent, write "[ASSUMPTION: ...]" and keep it conservative. Anything time-sensitive gets "[VERIFY BEFORE LAUNCH]".
3. Every external claim from search must name its source inline (publication + year).
4. Pipeline discipline: diagnosis before strategy before tactics (Ritson). Compliance before creativity. Leverage must be specific — reject generic leverage like "post consistently".
5. Canon governs choices: Sharp (mental availability, CEPs, distinctive assets, 95/5), Binet & Field (60/40 brand:activation, ESOV), Schwartz awareness stages, Berger STEPPS, StoryBrand, Hormozi offer test, SPACES for community, zero-click platform economics.
6. Regulated verticals: screen against industry marketing law (e.g. MY KKM/MAB, BNM/SC, MCMC/PDPA; SG MAS/ASAS/MOH; US FTC/FDA/SEC; UK FCA/ASA; PRC Ad Law) AND per-platform restricted-category policy (Meta special ad categories, TikTok restricted industries, YouTube MFK, Pinterest weight-loss ban, XHS 报备). Mark tactics Allowed / Conditional / Prohibited. Always note that final creative needs qualified legal sign-off.
7. Output EXACTLY the JSON schema requested — no markdown fences, no preamble, valid JSON only.
DOCTRINE;

    /**
     * The per-section prompts, ported verbatim from the artifact's GEN table
     * (lines 346-359), keyed by section_key. Which sections run a live search is
     * declared once on SocialStrategy::SECTIONS.
     */
    public const GEN = [
        'market' => 'Run Phase 2 market intelligence with LIVE web search: industry pulse, platform deltas (last 90 days) for likely channels, audience behavior in the stated jurisdictions, regulatory news, category benchmarks. Cite source+year for each finding. Return ONLY JSON {"section":"Market Intelligence","content":"..."} — content is plain text with line breaks, ≤500 words.',
        'competitor' => 'Run Phase 3 with LIVE search on the named competitors (and infer 1-2 more from the category): profiles, SoV/ESOV position, content gaps, positioning map (name the two axes), distinctive-asset audit, then the WHITE SPACE STATEMENT and UNIQUE LEVERAGE (VRIO-tested, specific — reject generic leverage). Return ONLY JSON {"section":"Competitor & Leverage","content":"..."} ≤500 words.',
        'compliance' => 'Run Phase 4 dual compliance gate for this vertical × every jurisdiction × likely platforms. Output a compliance matrix in text-table form (tactic | verdict Allowed/Conditional/Prohibited | condition/approval), mandatory approvals list, prohibited list, disclosure standard, and the legal sign-off note. Verify anything uncertain via search; tag stale items [VERIFY BEFORE LAUNCH]. Return ONLY JSON {"section":"Compliance Matrix","content":"..."} ≤500 words.',
        'strategy' => 'Run Phase 5 governed by the canon: positioning statement + messaging house; channel-mix scoring (lead vs support + what we will NOT do); 3-5 content pillars each mapped to awareness stage × Category Entry Point × STEPPS levers × red-lines, incl. one Unique Leverage pillar and one search pillar; zero-click funnel; brand:activation ratio with reason + 70/20/10; influencer/community posture (SPACES); Hormozi offer verdict. Return ONLY JSON {"section":"Strategy","content":"..."} ≤600 words.',
        'measure' => 'Run Phase 6 + roadmap: 90-day month-by-month roadmap tied to seasonal anchors; KPI tree (business→marketing→channel) with floor/target/stretch; split scorecard (brand vs activation — brand never judged on weekly ROAS); dark-social measurement (branded search lift, owned-audience growth); kill/scale thresholds; top-5 risk register with mitigations. Return ONLY JSON {"section":"Roadmap & Measurement","content":"..."} ≤500 words.',
        'handoff' => 'Write (a) EXECUTIVE SUMMARY ≤10 lines (goal, leverage, lead channels, budget ratio, headline targets); (b) execution handoff brief: pillars, guardrails, compliance red-lines, first 5 campaign concepts; (c) the CLAUDE vs CLIENT split — what the agent can produce next vs what the client must do personally (legal sign-off, account setup, budget approval, regulator submissions, contracts, assets); (d) [VERIFY BEFORE LAUNCH] list. Return ONLY JSON {"section":"Executive Summary & Handoff","content":"..."} ≤500 words.',
    ];

    private ?string $company;

    private ?string $modelOverride;

    /** Cached [key, model] once resolved. */
    private ?array $resolved = null;

    public function __construct(?string $company = null, ?string $modelOverride = null)
    {
        $this->company = $company;
        $this->modelOverride = $modelOverride;
    }

    /** Build the service for a specific strategy (company + model come from it). */
    public static function for(SocialStrategy $strategy): self
    {
        return new self($strategy->company, $strategy->model);
    }

    // ── Gap check (one call) ─────────────────────────────────────────────
    /**
     * Read the knowledge base + intake and return the factbase + gap questions,
     * persisting them on the strategy. One Claude call — safe to run in a web
     * request (well under the edge timeout).
     *
     * @return array{factbase:string, gaps:array<int,array{q:string,why:string,suggestion:string}>}
     */
    public function gapCheck(SocialStrategy $strategy): array
    {
        $content = array_merge($this->binaryBlocks($strategy), [[
            'type' => 'text',
            'text' => $this->contextBlock($strategy)."\n\n"
                ."TASK 1 — FACTBASE: Extract the key verifiable client facts into a TERSE bullet list — facts only, no interpretation. HARD LIMIT ~250 words; keep only what a strategist needs.\n"
                ."TASK 2 — GAP CHECK: List the questions that MUST be answered before a zero-guess strategy is possible. For each give: q = the question (≤20 words), why = why it matters strategically (≤25 words), suggestion = one recommended default the user can accept in one tap (≤30 words). Max 8 gaps, most critical first.\n"
                .'Output ONLY compact JSON — no markdown fences, no preamble: {"factbase":"...","gaps":[{"q":"...","why":"...","suggestion":"..."}]}. Keep the WHOLE response well under 1200 words so it stays complete and valid JSON.',
        ]]);

        // Output is length-capped in the prompt above (a factbase over several
        // decks was running to 6000+ tokens and truncating into invalid JSON, and
        // a bigger budget would exceed the ~100s edge timeout for this synchronous
        // call). 6000 is now generous headroom for the capped response.
        $j = $this->callClaudeJson($content, false, 'strategist_gap_check', 6000)['data'];

        $gaps = array_slice($j['gaps'] ?? [], 0, 8);
        $strategy->forceFill([
            'factbase' => (string) ($j['factbase'] ?? ''),
            'gaps_json' => array_values($gaps),
            'gap_answers_json' => [],
        ])->save();

        return ['factbase' => (string) $strategy->factbase, 'gaps' => array_values($gaps)];
    }

    // ── Generate one section (one call) ──────────────────────────────────
    /**
     * @return array{title:string, content:string, live:bool}
     */
    public function generateSection(SocialStrategy $strategy, string $key, string $accumulated = ''): array
    {
        $prompt = self::GEN[$key] ?? null;
        if ($prompt === null) {
            throw new \InvalidArgumentException("Unknown strategy section: {$key}");
        }

        $useSearch = (SocialStrategy::SECTIONS[$key]['search'] ?? false) && $strategy->use_web_search;

        $gaps = $strategy->gaps_json ?? [];
        $answers = $strategy->gap_answers_json ?? [];
        $gapQA = collect($gaps)->map(function ($g, $i) use ($answers) {
            return 'Q: '.($g['q'] ?? '')."\nA: ".($answers[$i] ?? '');
        })->implode("\n");

        $userText = "FACTBASE (only source of client truth):\n{$strategy->factbase}\n\n"
            ."INTAKE:\n".$this->summaryText($strategy)."\n\n"
            ."GAP-CHECK ANSWERS (user-confirmed truth):\n{$gapQA}\n\n"
            ."SECTIONS ALREADY WRITTEN (stay consistent, don't repeat):\n".mb_substr($accumulated, -6000)."\n\n"
            .$prompt;

        // Sections run in the background job (no edge limit), so allow an extra
        // parse re-roll before giving up on a section.
        $res = $this->callClaudeJson([['type' => 'text', 'text' => $userText]], $useSearch, 'strategist_'.$key, 6000, 3);
        $j = $res['data'];

        return [
            'title' => (string) ($j['section'] ?? (SocialStrategy::SECTIONS[$key]['label'] ?? ucfirst($key))),
            'content' => (string) ($j['content'] ?? ''),
            // Only claim LIVE-SOURCED when search was requested AND actually ran
            // (a search-tool failure silently falls back to no-search).
            'live' => $useSearch && $res['searched'],
        ];
    }

    // ── Claude transport ─────────────────────────────────────────────────
    /**
     * One logical Claude call with retry + a no-search fallback.
     *
     * When $useSearch is true and every search attempt fails, it retries once
     * without the tool so a flaky search doesn't sink the section — `searched`
     * comes back false so the caller won't falsely flag it LIVE-SOURCED, and the
     * doctrine's [VERIFY BEFORE LAUNCH] discipline covers the un-sourced result.
     *
     * @param  array<int,array<string,mixed>>  $content  Anthropic user-content blocks
     * @return array{text:string, searched:bool}
     */
    public function callClaude(array $content, bool $useSearch, string $feature, int $maxTokens = 2000): array
    {
        $lastError = null;

        foreach ([$useSearch, false] as $withSearch) {
            for ($attempt = 0; $attempt <= self::MAX_RETRIES; $attempt++) {
                try {
                    return ['text' => $this->send($content, $withSearch, $feature, $maxTokens), 'searched' => $withSearch];
                } catch (\Throwable $e) {
                    $lastError = $e;
                    if ($attempt < self::MAX_RETRIES) {
                        usleep(1_200_000 * ($attempt + 1)); // backoff, matches the artifact
                    }
                }
            }

            // No point running the no-search pass when search was never requested.
            if (! $useSearch) {
                break;
            }
        }

        throw $lastError ?? new \RuntimeException('Claude call failed');
    }

    /** One raw /v1/messages call → joined text. Throws on any error. */
    private function send(array $content, bool $useSearch, string $feature, int $maxTokens): string
    {
        [$key, $model] = $this->config();

        $body = [
            'model' => $model,
            'max_tokens' => $maxTokens,
            'system' => self::DOCTRINE,
            // Disable extended thinking. Claude 5-family models (Sonnet 5 etc.)
            // think on complex prompts by default, and on a large PDF-laden
            // gap check that burned the ENTIRE token budget inside a `thinking`
            // block — the response came back stop_reason=max_tokens with no text
            // block at all, so the "join only text blocks" step got nothing and
            // threw "Claude returned no text content". Disabling thinking also
            // keeps the synchronous gap check well under the ~100s edge timeout
            // (measured 28s vs 87s with thinking on). The doctrine + structured
            // prompts carry quality without it.
            'thinking' => ['type' => 'disabled'],
            'messages' => [['role' => 'user', 'content' => $content]],
        ];

        if ($useSearch) {
            // Anthropic's server-side web search tool. Anthropic runs the search
            // and returns the results inline — no client tool loop needed.
            $body['tools'] = [[
                'type' => 'web_search_20250305',
                'name' => 'web_search',
                'max_uses' => self::MAX_SEARCHES,
            ]];
        }

        $resp = Http::timeout(180)
            ->withHeaders([
                'x-api-key' => $key,
                'anthropic-version' => '2023-06-01',
                // Needed for base64 PDF `document` blocks (gap check); harmless elsewhere.
                'anthropic-beta' => 'pdfs-2024-09-25',
                'content-type' => 'application/json',
            ])
            ->post('https://api.anthropic.com/v1/messages', $body);

        // Record spend even on partial/failed responses that still report usage.
        ClaudeUsageRecorder::record($feature, $model, $resp->json('usage'), $this->company);

        if ($resp->failed() || $resp->json('error')) {
            $msg = $resp->json('error.message') ?: ('HTTP '.$resp->status());
            throw new \RuntimeException('Claude API error: '.$msg);
        }

        // With web_search the response interleaves tool_use / tool_result blocks —
        // keep only the model's text, or the JSON parser would choke on tool noise.
        $text = collect($resp->json('content', []))
            ->filter(fn ($b) => ($b['type'] ?? null) === 'text')
            ->map(fn ($b) => $b['text'] ?? '')
            ->implode("\n");

        if (trim($text) === '') {
            throw new \RuntimeException('Claude returned no text content.');
        }

        return trim($text);
    }

    /**
     * Extract the JSON object from a model response. Strips markdown fences and
     * slices from the first "{" to the last "}", so any prose/citations around
     * the object (common with search) are ignored.
     *
     * @return array<string,mixed>
     */
    public function parseJson(string $text): array
    {
        $clean = preg_replace('/```json|```/i', '', $text) ?? $text;
        $start = strpos($clean, '{');
        $end = strrpos($clean, '}');

        if ($start === false || $end === false || $end < $start) {
            throw new \RuntimeException('No JSON object found in the AI response.');
        }

        $json = substr($clean, $start, $end - $start + 1);

        $data = json_decode($json, true);
        if (is_array($data)) {
            return $data;
        }

        // Repair pass: Claude often emits raw newlines/tabs INSIDE string values
        // (e.g. a bulleted factbase), which json_decode rejects with a "control
        // character" error. Replacing ASCII control bytes with spaces fixes those
        // strings without touching structure (whitespace between JSON tokens is
        // insignificant). Note: 0x00-0x1F are only ever ASCII controls — UTF-8
        // continuation/lead bytes are 0x80+, so multibyte text is untouched.
        $data = json_decode(preg_replace('/[\x00-\x1F]+/', ' ', $json), true);
        if (is_array($data)) {
            return $data;
        }

        throw new \RuntimeException('The AI response was not valid JSON.');
    }

    /**
     * callClaude + parseJson with a re-roll on parse failure.
     *
     * Model JSON is non-deterministic — an unescaped quote or stray token slips
     * through the repair pass occasionally. Re-calling the model almost always
     * yields clean JSON. Bounded so the synchronous gap check stays under the
     * ~100s edge timeout (2 tries ≈ 2×~30s worst case).
     *
     * @return array{data:array<string,mixed>, searched:bool}
     */
    private function callClaudeJson(array $content, bool $useSearch, string $feature, int $maxTokens, int $parseTries = 2): array
    {
        $lastError = null;

        for ($i = 0; $i < $parseTries; $i++) {
            $res = $this->callClaude($content, $useSearch, $feature, $maxTokens);
            try {
                return ['data' => $this->parseJson($res['text']), 'searched' => $res['searched'] ?? false];
            } catch (\Throwable $e) {
                $lastError = $e;
            }
        }

        throw $lastError ?? new \RuntimeException('The AI response was not valid JSON.');
    }

    // ── Prompt-context builders (ported from the artifact) ───────────────
    /** The 19-field intake, flattened to the artifact's summaryText shape. */
    public function summaryText(SocialStrategy $strategy): string
    {
        $d = $strategy->intake_json ?? [];
        $v = function (string $k) use ($d): string {
            $val = $d[$k] ?? null;
            if (is_array($val)) {
                return implode(', ', $val) ?: '—';
            }

            return filled($val) ? (string) $val : '—';
        };

        return "Client: {$v('client')}\n"
            ."Industry: {$v('industry')}\n"
            ."Offering: {$v('offering')}\n"
            ."Goal: {$v('goal')}\n"
            ."Success: {$v('success')}\n"
            ."Jurisdictions: {$v('juris')}\n"
            ."Audience: {$v('audience')}\n"
            ."Sales motion: {$v('salesmotion')}\n"
            ."Competitors: {$v('competitors')}\n"
            ."Budget: {$v('budget')}\n"
            ."Team: {$v('team')}\n"
            ."Approval: {$v('approval')}\n"
            ."Assets: {$v('assets')}\n"
            ."Licenses: {$v('licenses')}\n"
            ."Strikes: {$v('strikes')}\n"
            ."Red lines: {$v('redlines')}\n"
            ."Timeline: {$v('timeline')}\n"
            ."Seasonal: {$v('seasonal')}\n"
            ."History: {$v('history')}";
    }

    /** Intake + notes + links + clean text-file extracts (capped), for the gap check. */
    public function contextBlock(SocialStrategy $strategy): string
    {
        $files = $strategy->files()->where('scan_status', SocialStrategyFile::SCAN_CLEAN)->get();

        $textFiles = mb_substr(
            $files->filter(fn ($f) => filled($f->extracted_text))
                ->map(fn ($f) => "--- FILE {$f->original_name} ---\n{$f->extracted_text}")
                ->implode("\n"),
            0,
            90000
        );

        $links = collect($strategy->kb_links_json ?? [])
            ->map(fn ($l) => ($l['url'] ?? '').' ('.($l['note'] ?? 'no description').')')
            ->implode("\n") ?: '—';

        $binaryNames = $files->filter(fn ($f) => $f->isBinary())
            ->map(fn ($f) => $f->original_name)->implode(', ') ?: 'none';

        return "CLIENT INTAKE:\n".$this->summaryText($strategy)."\n\n"
            ."KB NOTES:\n".($strategy->kb_notes ?: '—')."\n\n"
            ."CLOUD LINKS PROVIDED:\n{$links}\n\n"
            ."TEXT FILES:\n".($textFiles ?: '—')."\n\n"
            ."BINARY FILES ATTACHED: {$binaryNames}";
    }

    /**
     * Up to 5 clean PDF/image files as base64 document/image blocks read from the
     * private disk. Only scanned-clean files are ever read.
     *
     * @return array<int,array<string,mixed>>
     */
    public function binaryBlocks(SocialStrategy $strategy): array
    {
        $files = $strategy->files()
            ->where('scan_status', SocialStrategyFile::SCAN_CLEAN)
            ->whereIn('kind', ['pdf', 'image'])
            ->limit(5)
            ->get();

        $blocks = [];
        foreach ($files as $f) {
            $abs = Storage::disk('local')->path($f->file_path);
            if (! is_file($abs)) {
                continue;
            }
            $b64 = base64_encode((string) file_get_contents($abs));
            $blocks[] = $f->kind === 'pdf'
                ? ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => $b64]]
                : ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $f->mime ?: 'image/png', 'data' => $b64]];
        }

        return $blocks;
    }

    // ── Key/model resolution ─────────────────────────────────────────────
    /**
     * Resolve [key, model]. The superadmin Claude API page wins; else an
     * Anthropic-provider Accounting settings row; else fail loud (the caller
     * turns this into a friendly "configure a Claude key" message).
     *
     * Only the KEY is inherited from those sources — the MODEL is the
     * strategist's own default (Sonnet 5), overridable per strategy. Those pages
     * set their model for cheap receipt OCR (Haiku), which is too weak for
     * canon-governed strategy; inheriting it would silently downgrade generation
     * and contradict the "Default (Claude Sonnet 5)" label on the KB picker.
     *
     * @return array{0:string,1:string}
     */
    private function config(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $model = $this->modelOverride ?: self::DEFAULT_MODEL;

        $claude = ClaudeApiSetting::current();
        if ($claude->isActive()) {
            return $this->resolved = [$claude->getRawKey(), $model];
        }

        $settings = AccountingSetting::resolveForAi($this->company);
        if ($settings && $settings->ai_provider === 'anthropic' && $settings->ai_api_key) {
            return $this->resolved = [$settings->ai_api_key, $model];
        }

        throw new \RuntimeException(
            'No Claude API key is configured. Ask a superadmin to set one on the Claude API page (/superadmin/claude-api).'
        );
    }
}
