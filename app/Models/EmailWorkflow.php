<?php

namespace App\Models;

use Cron\CronExpression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * An Email Workflow automation: Email Source → Detection Rules →
 * Storage Destination → Log Destination, on a capture + reconcile schedule.
 *
 * Lives under IT > Automation > Email Workflow. Owned by the user who
 * created it (app-layer tenant scoping via `created_by` + scopeOwnedBy).
 */
class EmailWorkflow extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PAUSED = 'paused';

    public const STATUS_ERROR = 'error';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_ACTIVE,
        self::STATUS_PAUSED,
        self::STATUS_ERROR,
    ];

    /**
     * Statuses the scheduler sweeps.
     *
     * `error` is IN, and that is the whole point: this column conflates operator
     * INTENT (draft/active/paused) with HEALTH (error), and CaptureService only
     * ever sets `error` on a workflow that was Active. So `error` means "enabled,
     * but the last run failed" — not "disabled". Sweeping only `active` made one
     * failed run a trapdoor: the workflow left the schedule permanently, and could
     * never heal because healing needs a success and a success needs a sweep. A
     * five-minute mailbox outage retired the automation until a human noticed.
     *
     * Retrying a genuinely broken workflow is cheap — captures are cron-paced
     * (daily by default), the login just fails again, and the error stays visible
     * on the list. Whereas an automation that silently stops forever is the exact
     * failure this module keeps producing.
     *
     * `paused` and `draft` stay OUT: those are intent, and a run must never
     * override what the operator asked for.
     */
    public const SWEEPABLE_STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_ERROR,
    ];

    /** Total wizard steps (Source → Rules → Storage → Log → Schedule). */
    public const TOTAL_STEPS = 5;

    /** Sensible defaults pre-filled from the primary use case (invoices/receipts). */
    public const DEFAULT_RULES = [
        'subject' => [
            'enabled' => true,
            'mode' => 'contains',          // 'contains' | 'regex'
            'keywords' => ['invoice', 'receipt', 'credit note', 'payment received'],
        ],
        'body' => [
            'enabled' => false,
            'mode' => 'contains',
            'keywords' => [],
        ],
        'combine_subject_body' => 'or',        // 'and' | 'or'
        'attachment' => [
            'required' => true,
            'types' => ['pdf'],     // pdf|png|jpg|docx|xlsx
            'filename_mode' => 'contains',     // 'contains' | 'regex'
            'filename_keywords' => ['invoice', 'receipt', 'credit note'],
        ],
        'sender' => [
            'allowlist' => [],
            'denylist' => [],
        ],
        // capture when (attachment matches) AND (subject OR body matches)
        'capture_logic' => 'attachment_and_text',
    ];

    /**
     * How the attachment and subject/body verdicts combine. Keyed by the stored
     * value so the wizard's select and the validator can't drift apart.
     *
     * `attachment_or_text` is the widest and the right default for a dedicated
     * AP mailbox: either a document-shaped filename OR invoice wording in the
     * subject is enough. See DetectionEngine::evaluate() for the capture-set
     * fallback that keeps a text-only hit from storing nothing.
     */
    public const CAPTURE_LOGICS = [
        'attachment_and_text' => 'Attachment matches AND subject/body matches',
        'attachment_or_text' => 'Attachment matches OR subject/body matches (widest — best for an invoice mailbox)',
        'attachment_only' => 'Attachment matches only',
        'text_only' => 'Subject/body matches only',
    ];

    /**
     * Supplier-invoice preset — the document shapes actually arriving in the
     * group's AP mail, taken from an audit of invoices the generic defaults
     * missed (Aug 2026).
     *
     * Why the generic defaults missed them: DEFAULT_RULES require an
     * invoice/receipt word in BOTH the filename and the subject. Real supplier
     * documents carry a house reference instead — CDSB-IV-2608-002, ENSB-IO-02452,
     * I-001068, CHS26051383, SOA-20260731 — and arrive under subjects like
     * "Subscription Billing | August-2026" or "Statement of Account" that never
     * say "invoice". Every one of those is a silent miss, and a missed supplier
     * invoice is a missed payment.
     *
     * So the filenames are matched as regex (house codes need precision a
     * substring can't express) and the two sides are OR'd, not AND'd.
     *
     * Applied to a live workflow by `email-workflows:apply-invoice-preset`,
     * which MERGES rather than overwrites, and loadable into the wizard's
     * step 2 form with the "Load supplier-invoice preset" button.
     */
    public const SUPPLIER_INVOICE_RULES = [
        'subject' => [
            'enabled' => true,
            'mode' => 'contains',
            'keywords' => [
                'invoice', 'tax invoice', 'e-invoice', 'invois',
                'receipt', 'credit note', 'debit note',
                'statement of account', 'account statement',
                'billing', 'subscription billing', 'rental invoice',
                'proforma', 'remittance', 'payment received', 'purchase order',

                // ── Collections: SOAs and payment chasers (added Aug 2026) ──
                // A statement of account and a payment follow-up ARE finance
                // documents — the July audit found them arriving with a
                // statement or a bank transfer slip attached and being dropped,
                // because the subject says "PAYMENT FOLLOW UP" and never says
                // "invoice".
                //
                // Every phrase here is deliberately multi-word. `contains` is a
                // plain substring match, so a bare "soa" would fire on "soap"
                // and "soar", and a bare "follow up" would swallow every thread
                // in the mailbox that happens to be a follow-up about anything.
                // SOA files are caught on the FILENAME side instead, where the
                // regex mode can demand real word boundaries.
                'payment follow up', 'payment follow-up', 'follow up on payment',
                'outstanding debt', 'outstanding payment', 'outstanding balance',
                'overdue', 'payment reminder', 'demand for payment',
            ],
        ],
        'body' => [
            'enabled' => false,
            'mode' => 'contains',
            'keywords' => [],
        ],
        'combine_subject_body' => 'or',
        'attachment' => [
            'required' => true,
            'types' => ['pdf'],
            'filename_mode' => 'regex',
            'filename_keywords' => [
                // ── House document codes ─────────────────────────────────
                'CDSB-\s*IV-\d',                    // CDSB-IV-2608-002 (Telecontinent)
                'CDSB-\s*SOA-\d',                   // CDSB- SOA-20260731-Telecontinent
                'ENSB-\s*IO-\d',                    // ENSB-IO-02452- Bio-Oil
                '(?<![a-z0-9])CHS\d{6,}',           // NUREN GROUP LIMITED CHS26051383
                '(?<![a-z0-9])I-\d{6}(?!\d)',       // I-001068 / I-038276 Sdn Bhd
                '(?<![a-z])INV[-_ ]?\d{3,}',        // INV-1042, INV 1042
                'care[\s_-]*digital[\s_-]*-[\s_-]*\d{6}',  // Care Digital - 082026 (rental)

                // ── Generic document vocabulary ──────────────────────────
                // Letter-only lookarounds, not \b: "\bSOA\b" fails on
                // SOA_202607.pdf because "_" is a word character, and
                // "\binvoice\b" fails on tax_invoice.pdf for the same reason.
                '(?<![a-z])SOA(?![a-z])',
                'invoice',
                'invois',
                'receipt',
                'credit[\s_-]*note',
                'debit[\s_-]*note',
                'statement[\s_-]*of[\s_-]*account',

                // ── What a payment chaser actually carries ───────────────
                // Not an invoice — a statement, or proof of a transfer. The
                // July audit found these attached to "PAYMENT FOLLOW UP" and
                // "OUTSTANDING DEBT" threads and dropped by every run.
                // M2U is Maybank2u, whose slips arrive as
                // NCSB-M2U-20260720-accordia.pdf; the lookarounds keep it off
                // words that merely contain the letters.
                '(?<![a-z0-9])M2U(?![a-z0-9])',
                'payment[\s_-]*advice',
                'remittance[\s_-]*advice',
                'payment[\s_-]*slip',
                'transfer[\s_-]*slip',
                'outstanding',
                'overdue',
            ],
        ],
        'sender' => [
            'allowlist' => [],
            'denylist' => [],
        ],
        'capture_logic' => 'attachment_or_text',
    ];

    public const DEFAULT_STORAGE_CONFIG = [
        'folder_ref' => '',             // Drive folder URL/ID
        'monthly_subfolders' => true,         // organize into YYYY-MM
        'filename_template' => '{{date}}_{{originalName}}',
    ];

    public const DEFAULT_LOG_CONFIG = [
        'target_ref' => '',             // Google Sheet URL/ID
        'partition_by_month' => true,         // one tab per month
        'columns' => [
            ['label' => 'Date received',    'source' => 'email.date'],
            ['label' => 'Vendor/From',      'source' => 'email.from'],
            ['label' => 'Subject',          'source' => 'email.subject'],
            ['label' => 'Amount',           'source' => 'parsed.amount'],
            ['label' => 'Item description', 'source' => 'parsed.description'],
            ['label' => 'File name',        'source' => 'attachment.name'],
            ['label' => 'File link',        'source' => 'storage.url'],
            ['label' => 'Message ID',       'source' => 'email.message_id'],
        ],
        // hidden idempotency key = message_id|attachment_name
        'idempotency_columns' => ['email.message_id', 'attachment.name'],
    ];

    public const DEFAULT_CAPTURE_CRON = '0 19 * * *';   // daily 19:00 local

    /** New workflows are handed the next free slot this many minutes apart. */
    public const CAPTURE_STAGGER_STEP_MINUTES = 15;

    public const DEFAULT_RECONCILE_CRON = '0 7 * * *';    // daily 07:00 local

    protected $fillable = [
        'created_by', 'name', 'status',
        'email_connection_id', 'storage_connection_id', 'log_connection_id',
        'rules_json', 'storage_config_json', 'log_config_json',
        'timezone', 'capture_cron', 'reconcile_cron', 'first_sweep_on_activate',
        'wizard_step', 'last_run_at', 'next_run_at', 'captured_count', 'last_error',
        'run_requested_at', 'run_requested_by',
    ];

    protected $casts = [
        'rules_json' => 'array',
        'storage_config_json' => 'array',
        'log_config_json' => 'array',
        'first_sweep_on_activate' => 'boolean',
        'wizard_step' => 'integer',
        'captured_count' => 'integer',
        'last_run_at' => 'datetime',
        'next_run_at' => 'datetime',
        'run_requested_at' => 'datetime',
    ];

    // ── Relationships ────────────────────────────────────────────────────
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function emailConnection(): BelongsTo
    {
        return $this->belongsTo(EmailWorkflowConnection::class, 'email_connection_id');
    }

    public function storageConnection(): BelongsTo
    {
        return $this->belongsTo(EmailWorkflowConnection::class, 'storage_connection_id');
    }

    public function logConnection(): BelongsTo
    {
        return $this->belongsTo(EmailWorkflowConnection::class, 'log_connection_id');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(EmailWorkflowRun::class, 'email_workflow_id');
    }

    public function runRequester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'run_requested_by');
    }

    /**
     * Mark this workflow for an immediate out-of-band sweep.
     *
     * "Run now" cannot sweep inline: a slow mailbox takes minutes and the edge
     * proxy 504s at ~100s. So it drops a marker the every-minute scheduler picks
     * up (CLI, no edge timeout) and returns at once. Idempotent — clicking twice
     * before the scheduler ticks just re-stamps the same pending request.
     */
    public function requestImmediateRun(?int $userId = null): void
    {
        $this->forceFill([
            'run_requested_at' => now(),
            'run_requested_by' => $userId,
        ])->save();
    }

    public function hasPendingRunRequest(): bool
    {
        return $this->run_requested_at !== null;
    }

    public function clearRunRequest(): void
    {
        $this->forceFill(['run_requested_at' => null, 'run_requested_by' => null])->save();
    }

    public function captures(): HasMany
    {
        return $this->hasMany(EmailWorkflowCapture::class, 'email_workflow_id');
    }

    /** The most recent run — drives "Last run" on the list page. */
    public function latestRun(): HasOne
    {
        return $this->hasOne(EmailWorkflowRun::class, 'email_workflow_id')->latestOfMany();
    }

    // ── Scopes (app-layer tenant isolation) ──────────────────────────────
    /**
     * Restrict to workflows the given user may see. Superadmin/system_admin
     * + IT managers see all; everyone else sees only their own.
     */
    public function scopeVisibleTo(Builder $q, User $user): Builder
    {
        if ($user->isSuperadmin() || $user->role === 'system_admin' || $user->isItManager()) {
            return $q;
        }

        return $q->where('created_by', $user->id);
    }

    // ── Derived state ────────────────────────────────────────────────────
    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * Has the operator switched this on? — i.e. will the scheduler sweep it.
     *
     * Distinct from isActive(), and the distinction is the whole point: `error`
     * is a HEALTH value stored in the same column as INTENT, so an errored
     * workflow is switched ON and running while `isActive()` is false. Rendering
     * the Active toggle from isActive() therefore drew it OFF next to a workflow
     * that runs every day — the UI claiming something untrue, which is the exact
     * failure this module keeps fighting.
     *
     * Definitionally the sweep set, so the switch and the scheduler cannot drift:
     * if it is swept, the switch is on.
     */
    public function isEnabled(): bool
    {
        return in_array($this->status, self::SWEEPABLE_STATUSES, true);
    }

    /**
     * Everything still standing between this workflow and a successful run, in
     * plain language the operator can act on.
     *
     * Single source of truth: isReadyToActivate(), the toggle's tooltip, the
     * wizard's banner and the step chips all derive from this, so they cannot
     * drift apart and contradict each other on screen.
     *
     * Connections are checked for HEALTH, not merely for a non-null FK. A row
     * selected while still `pending` (credentials saved, consent not yet given)
     * or `needs_reconnect` (token revoked/expired) would otherwise sail past
     * this gate and fail at run time in CaptureService::connections().
     *
     * @return array<int,string>
     */
    public function missingRequirements(): array
    {
        $missing = [];

        foreach ([
            ['label' => 'email source', 'step' => 1, 'conn' => $this->emailConnection],
            ['label' => 'storage account', 'step' => 3, 'conn' => $this->storageConnection],
            ['label' => 'log account', 'step' => 4, 'conn' => $this->logConnection],
        ] as $slot) {
            if (! $slot['conn']) {
                $missing[] = "select a {$slot['label']} on step {$slot['step']}";
            } elseif (! $slot['conn']->isConnected()) {
                $missing[] = "reconnect the {$slot['label']} on step {$slot['step']} "
                    ."(it is {$slot['conn']->status})";
            }
        }

        if (blank(data_get($this->storage_config_json, 'folder_ref'))) {
            $missing[] = 'set a destination folder on step 3';
        }
        if (blank(data_get($this->log_config_json, 'target_ref'))) {
            $missing[] = 'set a log sheet on step 4';
        }

        // An unparseable cron is a DEAD schedule: RunEmailWorkflows skips the
        // workflow every minute forever, and the only trace is a log line nobody
        // reads. Without this check the list page cheerfully reports "Active,
        // nothing missing" about an automation that will never fire again.
        if (! self::isValidCron($this->effectiveCaptureCron())) {
            $missing[] = 'fix the capture schedule on step 5 — “'.$this->capture_cron
                .'” is not a cron expression (e.g. 0 19 * * * for 7pm daily)';
        }

        return $missing;
    }

    /**
     * The expression the scheduler will actually evaluate.
     *
     * Blank falls back to the default, so a blank cron is NOT a dead schedule —
     * only a non-blank unparseable one is. RunEmailWorkflows reads this same
     * method, so the readiness gate and the runtime can't disagree about what
     * will run.
     */
    public function effectiveCaptureCron(): string
    {
        return $this->capture_cron ?: self::DEFAULT_CAPTURE_CRON;
    }

    /**
     * A non-colliding default capture cron for a NEW workflow.
     *
     * All workflows used to be born at DEFAULT_CAPTURE_CRON (19:00), so a fleet
     * of them dispatched in the same minute and the single scheduler-supervised
     * worker drained them back-to-back in one long run (see the queue-fix
     * history). Instead, hand each new workflow the next free
     * CAPTURE_STAGGER_STEP_MINUTES slot from the base time onward. This is only
     * the starting default — the wizard's step 5 still lets the operator set any
     * cron they want.
     *
     * Only simple "daily at HH:MM" crons (`M H * * *`) count as occupied slots;
     * a custom expression is the operator's deliberate choice and never blocks
     * or shifts the stagger. If every slot from the base time to 23:59 is taken,
     * fall back to the base default — a collision at that scale is a "run more
     * workers" problem, not a scheduling one.
     */
    public static function nextStaggeredCron(): string
    {
        $base = self::cronSlotMinutes(self::DEFAULT_CAPTURE_CRON) ?? (19 * 60);

        $occupied = [];
        foreach (self::query()->whereNotNull('capture_cron')->pluck('capture_cron') as $cron) {
            if (($slot = self::cronSlotMinutes($cron)) !== null) {
                $occupied[$slot] = true;
            }
        }

        for ($slot = $base; $slot < 24 * 60; $slot += self::CAPTURE_STAGGER_STEP_MINUTES) {
            if (! isset($occupied[$slot])) {
                return sprintf('%d %d * * *', $slot % 60, intdiv($slot, 60));
            }
        }

        return self::DEFAULT_CAPTURE_CRON;
    }

    /**
     * Minute-of-day for a simple "daily at HH:MM" cron (`M H * * *`), or null if
     * the expression is anything more complex (ranges, steps, lists, wildcards)
     * or out of range — such expressions are left out of stagger slot accounting.
     */
    private static function cronSlotMinutes(?string $cron): ?int
    {
        if ($cron !== null && preg_match('/^\s*(\d{1,2})\s+(\d{1,2})\s+\*\s+\*\s+\*\s*$/', $cron, $m)) {
            $minute = (int) $m[1];
            $hour = (int) $m[2];
            if ($minute < 60 && $hour < 24) {
                return $hour * 60 + $minute;
            }
        }

        return null;
    }

    /** Is this a cron expression the scheduler can actually evaluate? */
    public static function isValidCron(?string $expression): bool
    {
        if (blank($expression)) {
            return false;
        }

        try {
            return CronExpression::isValidExpression($expression);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Whether the workflow can legitimately be switched Active. Drives the
     * toggle's enabled state and the Run-now guard.
     */
    public function isReadyToActivate(): bool
    {
        return $this->missingRequirements() === [];
    }

    /**
     * Is this step genuinely configured? Steps 2 and 5 are seeded with working
     * defaults by store(), so they are complete from birth — which is exactly
     * why completeness alone must never drive the chips (see stepDone()).
     */
    public function stepComplete(int $step): bool
    {
        $healthy = fn (?EmailWorkflowConnection $c) => $c !== null && $c->isConnected();

        return match ($step) {
            1 => filled($this->name) && $healthy($this->emailConnection),
            2 => filled($this->rules_json),
            3 => $healthy($this->storageConnection)
                && filled(data_get($this->storage_config_json, 'folder_ref')),
            4 => $healthy($this->logConnection)
                && filled(data_get($this->log_config_json, 'target_ref')),
            5 => filled($this->timezone) && filled($this->capture_cron) && filled($this->reconcile_cron),
            default => false,
        };
    }

    /**
     * Should the stepper paint this step green?
     *
     * Visitation AND completeness — neither alone is honest:
     *  - `wizard_step` alone (the old behaviour) is a navigation high-water mark
     *    that only ever rises. It painted steps green that were never configured,
     *    and kept them green after a connection was deleted out from under them.
     *  - `stepComplete()` alone would paint steps 2 and 5 green on a brand-new
     *    workflow, because store() seeds valid rules/schedule defaults.
     *
     * wizard_step keeps its real job (where to resume) and is necessary but not
     * sufficient; stepComplete() is the authority. Because completeness is
     * re-derived on every render, a step that loses its config goes grey again.
     *
     * The final step needs the `>= TOTAL_STEPS` arm because update() caps
     * wizard_step at TOTAL_STEPS, so `5 < 5` is never true and step 5 could
     * otherwise never show as done. Jumping straight to step 5 is still safe:
     * steps 1–4 fail stepComplete() and stay grey.
     */
    public function stepDone(int $step): bool
    {
        if (! $this->exists) {
            return false;
        }

        $visited = $step < $this->wizard_step || $this->wizard_step >= self::TOTAL_STEPS;

        return $visited && $this->stepComplete($step);
    }

    public function statusBadgeClass(): string
    {
        return match ($this->status) {
            self::STATUS_ACTIVE => 'bg-success',
            self::STATUS_PAUSED => 'bg-secondary',
            self::STATUS_ERROR => 'bg-danger',
            default => 'bg-warning text-dark', // draft
        };
    }
}
