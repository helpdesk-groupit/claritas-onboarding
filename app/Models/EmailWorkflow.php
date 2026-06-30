<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
            'filename_keywords' => ['invoice', 'receipt', 'credit note'],
        ],
        'sender' => [
            'allowlist' => [],
            'denylist' => [],
        ],
        // capture when (attachment matches) AND (subject OR body matches)
        'capture_logic' => 'attachment_and_text',
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

    public const DEFAULT_RECONCILE_CRON = '0 7 * * *';    // daily 07:00 local

    protected $fillable = [
        'created_by', 'name', 'status',
        'email_connection_id', 'storage_connection_id', 'log_connection_id',
        'rules_json', 'storage_config_json', 'log_config_json',
        'timezone', 'capture_cron', 'reconcile_cron', 'first_sweep_on_activate',
        'wizard_step', 'last_run_at', 'next_run_at', 'captured_count', 'last_error',
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
     * Whether every connection + required config exists so the workflow can
     * legitimately be switched Active. Drives the toggle's enabled state.
     */
    public function isReadyToActivate(): bool
    {
        return $this->email_connection_id
            && $this->storage_connection_id
            && $this->log_connection_id
            && filled(data_get($this->storage_config_json, 'folder_ref'))
            && filled(data_get($this->log_config_json, 'target_ref'));
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
