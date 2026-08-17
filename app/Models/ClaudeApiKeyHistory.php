<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One row per Claude API key ever set on the "Claude API" settings page — a labeled
 * rotation ledger sitting beside `ClaudeApiSetting`'s single usable-key row, which
 * stays the only thing any AI feature actually calls Anthropic with. `ended_at IS
 * NULL` marks the currently active key. Only a masked hint is ever stored here —
 * never the raw key — since a superseded key's raw value is never needed again once
 * rotated out.
 */
class ClaudeApiKeyHistory extends Model
{
    protected $fillable = ['label', 'masked_key', 'set_by', 'started_at', 'ended_at'];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    public function setBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'set_by');
    }

    public function usageLogs(): HasMany
    {
        return $this->hasMany(ClaudeApiUsageLog::class);
    }

    /** The key presently in force, or null before the very first one is ever saved. */
    public static function current(): ?self
    {
        return static::whereNull('ended_at')->latest('id')->first();
    }

    public function isCurrent(): bool
    {
        return $this->ended_at === null;
    }

    /** Close the current history row (if any) and open a new one for $rawKey. */
    public static function rotate(string $rawKey, ?string $label, ?int $setBy): self
    {
        static::current()?->update(['ended_at' => now()]);

        return static::create([
            'label' => $label,
            'masked_key' => ClaudeApiSetting::maskKeyValue($rawKey),
            'set_by' => $setBy,
            'started_at' => now(),
        ]);
    }

    /** Relabel this row in place — no rotation, no new row. */
    public function relabel(?string $label): void
    {
        $this->update(['label' => $label]);
    }

    /**
     * Named displayLabel(), not label() — a method called label() beside a real
     * `label` attribute on the same model is legal PHP but reads as a trap.
     *
     * Falls back to the active date range (e.g. "17 Aug 2026 – present") rather than
     * a generic "unlabeled" placeholder — whose key this was is still an open
     * question, but WHEN it was active is a known fact and is exactly what's needed
     * to split spend for an expense claim before that naming question is settled.
     */
    public function displayLabel(): string
    {
        if (filled($this->label)) {
            return $this->label;
        }

        $from = $this->started_at->format('d M Y');
        $until = $this->ended_at ? $this->ended_at->format('d M Y') : 'present';

        return $from.' – '.$until;
    }
}
