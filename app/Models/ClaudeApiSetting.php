<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * Single-row settings for the "Claude API" superadmin page — the Anthropic API
 * key + model used for claim-receipt OCR. When active (enabled + key set), it is
 * the source of truth for OCR, overriding the env-based CLAIMS_OCR_* config.
 *
 * The api_key is encrypted at rest and hidden from serialization, so it never
 * leaks into JSON/responses. Read it only where you actually need to call the
 * API (ClaimReceiptOcrService). Use maskedKey() to show a safe hint in the UI.
 */
class ClaudeApiSetting extends Model
{
    /** Vision-capable Claude models offered on the settings page (label shown in the dropdown). */
    public const MODELS = [
        'claude-haiku-4-5' => 'Claude Haiku 4.5 — cheapest, great for receipts',
        'claude-sonnet-5' => 'Claude Sonnet 5 — higher accuracy',
        'claude-opus-4-8' => 'Claude Opus 4.8 — maximum accuracy',
    ];

    protected $fillable = ['api_key', 'model', 'enabled', 'updated_by'];

    protected $casts = [
        'api_key' => 'encrypted',
        'enabled' => 'boolean',
    ];

    protected $hidden = ['api_key'];

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** The single settings row, created on first access. */
    public static function current(): self
    {
        return static::query()->firstOrCreate([], ['model' => 'claude-haiku-4-5', 'enabled' => false]);
    }

    /** True when OCR should run through Claude (switched on AND a key is stored). */
    public function isActive(): bool
    {
        return $this->enabled && ! empty($this->getRawKey());
    }

    /** The decrypted key (or null). The `encrypted` cast handles decryption. */
    public function getRawKey(): ?string
    {
        return $this->api_key;
    }

    /** A safe hint for the UI: sk-ant-…last4, or null when no key is stored. */
    public function maskedKey(): ?string
    {
        $key = $this->getRawKey();

        return $key ? self::maskKeyValue($key) : null;
    }

    /** The 'sk-ant-…last4' formatting rule, shared with ClaudeApiKeyHistory so it can't drift. */
    public static function maskKeyValue(string $key): string
    {
        return 'sk-ant-…'.substr($key, -4);
    }

    public function modelLabel(): string
    {
        return self::MODELS[$this->model] ?? $this->model;
    }

    /**
     * Apply this save's key rotation and/or label edit in one transaction, so the
     * usable key and its rotation history can never disagree about what "current"
     * means.
     *
     *  - Non-blank $rawKey -> ROTATES: closes the current ClaudeApiKeyHistory row
     *    (if any), opens a new one, becomes the usable key on this row.
     *  - Blank $rawKey with a current history row -> relabels it in place, no rotation.
     *  - Blank $rawKey with no current history row -> no-op; nothing exists yet to label.
     *
     * Caller sets model/enabled/updated_by on $this before calling.
     */
    public function applyKeyAndLabel(?string $rawKey, ?string $label, ?int $actorId): void
    {
        $rawKey = trim((string) $rawKey);
        $label = trim((string) $label) ?: null;

        DB::transaction(function () use ($rawKey, $label, $actorId) {
            if ($rawKey !== '') {
                $this->api_key = $rawKey;
                $this->save();
                ClaudeApiKeyHistory::rotate($rawKey, $label, $actorId);
            } else {
                $this->save();
                ClaudeApiKeyHistory::current()?->relabel($label);
            }
        });
    }
}
