<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A Social Media AI Strategist engagement — the server-side port of the
 * "Strategist OS" browser agent.
 *
 * Lives under IT > Automation > Social Media AI Strategist. Owned by the user
 * who created it (app-layer tenant scoping via created_by + scopeVisibleTo).
 *
 * The pipeline is: 6-step intake → knowledge base → gap-check GATE → six
 * generated sections → editor/export. The anti-hallucination doctrine and the
 * AI calls live in SocialMediaStrategistService; this model owns state + gates.
 */
class SocialStrategy extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_GENERATING = 'generating';

    public const STATUS_READY = 'ready';

    public const STATUS_ERROR = 'error';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_GENERATING,
        self::STATUS_READY,
        self::STATUS_ERROR,
    ];

    /** Total intake wizard steps (business → market → budget → constraints → timeline → review). */
    public const TOTAL_STEPS = 6;

    /**
     * The generated sections, in render order, with whether each phase runs a
     * live web search. The prompts themselves live in
     * SocialMediaStrategistService::GEN keyed by the same section_key.
     */
    public const SECTIONS = [
        'market' => ['position' => 1, 'label' => 'Market intelligence', 'search' => true],
        'competitor' => ['position' => 2, 'label' => 'Competitor & leverage', 'search' => true],
        'compliance' => ['position' => 3, 'label' => 'Compliance matrix', 'search' => true],
        'strategy' => ['position' => 4, 'label' => 'Strategy', 'search' => false],
        'measure' => ['position' => 5, 'label' => 'Roadmap & measurement', 'search' => false],
        'handoff' => ['position' => 6, 'label' => 'Executive summary & handoff', 'search' => false],
    ];

    /** Every intake field the wizard collects (also the whitelist for update()). */
    public const INTAKE_FIELDS = [
        'client', 'industry', 'offering', 'goal', 'success',
        'juris', 'audience', 'salesmotion', 'competitors',
        'budget', 'team', 'approval', 'assets',
        'licenses', 'strikes', 'redlines',
        'timeline', 'seasonal', 'history',
    ];

    /** Multi-select (chip) fields — stored as arrays, not strings. */
    public const INTAKE_ARRAY_FIELDS = ['goal', 'juris'];

    /** Chip / select vocabularies for the intake wizard (ported from the artifact). */
    public const GOALS = [
        'Revenue / e-commerce sales', 'Qualified leads (B2B / high-ticket)', 'Footfall / bookings',
        'Brand launch or reposition', 'Recruitment', 'Community & retention',
    ];

    public const INDUSTRIES = [
        'Healthcare / clinic', 'Pharma / supplements', 'Finance / fintech', 'Insurance',
        'F&B / restaurant', 'Retail / e-commerce', 'Beauty / aesthetics', 'Real estate / property',
        'Education', 'Legal services', 'Travel / hospitality', 'Manufacturing / B2B industrial',
        'Oil & gas / energy', 'Technology / SaaS', 'Automotive', 'Crypto / web3', 'Other',
    ];

    public const JURISDICTIONS = [
        'Malaysia', 'Singapore', 'Indonesia', 'Thailand', 'Philippines', 'Vietnam',
        'United States', 'United Kingdom', 'EU', 'UAE / GCC', 'Australia', 'China (XHS/Douyin)', 'Global',
    ];

    /**
     * Required fields per intake step. The doctrine forbids guessing these, so
     * they gate progress (step chips) and the gap check.
     */
    public const STEP_REQUIRED = [
        1 => ['client', 'industry', 'offering', 'success', 'goal'],
        2 => ['juris', 'audience', 'salesmotion'],
        3 => ['budget'],
        4 => [],
        5 => ['timeline'],
        6 => [],
    ];

    /** Human labels for the required fields, used by missingRequirements(). */
    private const FIELD_LABELS = [
        'client' => 'client / brand name',
        'industry' => 'industry',
        'offering' => 'what is sold',
        'success' => 'success definition',
        'goal' => 'a primary business goal',
        'juris' => 'at least one jurisdiction',
        'audience' => 'audience hypothesis',
        'salesmotion' => 'sales motion',
        'budget' => 'monthly budget',
        'timeline' => 'timeline / horizon',
    ];

    protected $fillable = [
        'created_by', 'company', 'name', 'status', 'wizard_step',
        'intake_json', 'kb_notes', 'kb_links_json', 'integrations_json',
        'factbase', 'gaps_json', 'gap_answers_json',
        'meta_json', 'model', 'use_web_search', 'last_error', 'generated_at',
    ];

    protected $casts = [
        'intake_json' => 'array',
        'kb_links_json' => 'array',
        'integrations_json' => 'array',
        'gaps_json' => 'array',
        'gap_answers_json' => 'array',
        'meta_json' => 'array',
        'use_web_search' => 'boolean',
        'wizard_step' => 'integer',
        'generated_at' => 'datetime',
    ];

    // ── Relationships ────────────────────────────────────────────────────
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function files(): HasMany
    {
        return $this->hasMany(SocialStrategyFile::class);
    }

    public function sections(): HasMany
    {
        return $this->hasMany(SocialStrategySection::class)->orderBy('position');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(SocialStrategyRun::class);
    }

    public function latestRun(): HasOne
    {
        return $this->hasOne(SocialStrategyRun::class)->latestOfMany();
    }

    // ── Scopes (app-layer tenant isolation) ──────────────────────────────
    /**
     * Restrict to strategies the given user may see. Superadmin/system_admin +
     * IT managers see all; everyone else sees only their own. Mirrors
     * EmailWorkflow::scopeVisibleTo.
     */
    public function scopeVisibleTo(Builder $q, User $user): Builder
    {
        if ($user->isSuperadmin() || $user->role === 'system_admin' || $user->isItManager()) {
            return $q;
        }

        return $q->where('created_by', $user->id);
    }

    // ── Intake accessors ─────────────────────────────────────────────────
    /** One intake field, or a default. */
    public function intake(string $key, mixed $default = null): mixed
    {
        return ($this->intake_json ?? [])[$key] ?? $default;
    }

    /** The client/brand name for display (falls back to the workflow name). */
    public function clientName(): string
    {
        return (string) ($this->intake('client') ?: $this->name ?: 'Untitled strategy');
    }

    // ── Wizard-state quartet (mirrors EmailWorkflow) ─────────────────────
    /**
     * Is this intake step genuinely filled in? Drives green step chips together
     * with stepDone(). Step 6 (review) is complete once steps 1–5 are.
     */
    public function stepComplete(int $step): bool
    {
        if ($step === self::TOTAL_STEPS) {
            foreach ([1, 2, 3, 4, 5] as $s) {
                if (! $this->stepComplete($s)) {
                    return false;
                }
            }

            return true;
        }

        $intake = $this->intake_json ?? [];
        foreach (self::STEP_REQUIRED[$step] ?? [] as $field) {
            if ($this->fieldEmpty($intake[$field] ?? null)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Should the stepper paint this step green? Visitation AND completeness —
     * see EmailWorkflow::stepDone() for the full rationale. wizard_step is the
     * resume pointer; stepComplete() is the authority.
     */
    public function stepDone(int $step): bool
    {
        if (! $this->exists) {
            return false;
        }

        $visited = $step < $this->wizard_step || $this->wizard_step >= self::TOTAL_STEPS;

        return $visited && $this->stepComplete($step);
    }

    /**
     * Everything still missing from the intake before a zero-guess gap check is
     * possible, in plain language. Single source of truth for the "run gap
     * check" gate and the wizard banner.
     *
     * @return array<int,string>
     */
    public function missingRequirements(): array
    {
        $intake = $this->intake_json ?? [];
        $missing = [];

        foreach ([1, 2, 3, 4, 5] as $step) {
            foreach (self::STEP_REQUIRED[$step] as $field) {
                if ($this->fieldEmpty($intake[$field] ?? null)) {
                    $missing[] = (self::FIELD_LABELS[$field] ?? $field)." (step {$step})";
                }
            }
        }

        return $missing;
    }

    public function isReadyForGapCheck(): bool
    {
        return $this->missingRequirements() === [];
    }

    // ── Gap-check gate ───────────────────────────────────────────────────
    public function hasFactbase(): bool
    {
        return filled($this->factbase);
    }

    /**
     * Have all gap questions been answered? False when no gap check has run yet
     * (an empty gap set is not "all answered" — it means the gate isn't open).
     */
    public function allGapsAnswered(): bool
    {
        $gaps = $this->gaps_json ?? [];
        if (count($gaps) === 0) {
            return false;
        }

        $answers = $this->gap_answers_json ?? [];
        foreach (array_keys($gaps) as $i) {
            if (blank($answers[$i] ?? null)) {
                return false;
            }
        }

        return true;
    }

    /** The gate the generation job checks before writing a single section. */
    public function isReadyToGenerate(): bool
    {
        return $this->isReadyForGapCheck() && $this->hasFactbase() && $this->allGapsAnswered();
    }

    // ── Display ──────────────────────────────────────────────────────────
    public function statusBadgeClass(): string
    {
        return match ($this->status) {
            self::STATUS_READY => 'bg-success',
            self::STATUS_GENERATING => 'bg-info text-dark',
            self::STATUS_ERROR => 'bg-danger',
            default => 'bg-warning text-dark', // draft
        };
    }

    /** True when at least one section has been generated (drives "open editor"). */
    public function hasOutput(): bool
    {
        return $this->sections()->where('status', 'ok')->exists();
    }

    /** An empty array/string/null intake value. */
    private function fieldEmpty(mixed $v): bool
    {
        return is_array($v) ? count($v) === 0 : blank($v);
    }
}
