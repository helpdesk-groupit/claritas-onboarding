<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    protected $fillable = ['api_key', 'model', 'enabled', 'usd_myr_rate', 'updated_by'];

    protected $casts = [
        'api_key' => 'encrypted',
        'enabled' => 'boolean',
        'usd_myr_rate' => 'decimal:4',
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
        if (! $key) {
            return null;
        }

        return 'sk-ant-…'.substr($key, -4);
    }

    public function modelLabel(): string
    {
        return self::MODELS[$this->model] ?? $this->model;
    }
}
