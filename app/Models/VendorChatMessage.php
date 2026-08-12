<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One turn of the vendor profile's document Q&A thread.
 *
 * There is ONE thread per vendor, shared by everyone who can see that vendor — no
 * sessions, no per-user threads. The profile is a shared Finance+IT workspace, and an
 * answer about a contract is exactly what the next person to open the page needs.
 *
 * Nothing is ever deleted, for the same reason a signed AARF has no delete path: an AI
 * answer about a contract can drive a commercial decision, so what it was asked and what
 * it replied is a record. What bounds the CONTEXT is the `divider` role — the "Start new
 * topic" marker contextFor() stops at — not deletion.
 */
class VendorChatMessage extends Model
{
    public const ROLE_USER = 'user';

    public const ROLE_ASSISTANT = 'assistant';

    /** A "Start new topic" marker. Renders as a rule; the context builder stops here. */
    public const ROLE_DIVIDER = 'divider';

    protected $fillable = ['vendor_id', 'user_id', 'role', 'content', 'context_json', 'model'];

    protected $casts = ['context_json' => 'array'];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function isUser(): bool
    {
        return $this->role === self::ROLE_USER;
    }

    public function isAssistant(): bool
    {
        return $this->role === self::ROLE_ASSISTANT;
    }

    public function isDivider(): bool
    {
        return $this->role === self::ROLE_DIVIDER;
    }

    /**
     * The turns to send with the next question: the current topic only, capped.
     *
     * Walks BACK from the newest message and stops dead at the most recent divider, so
     * "Start new topic" genuinely starts one without destroying anything. The cap is a
     * second, independent bound — a single topic can still run long enough to be
     * expensive, and the documents are the context that matters, not the chatter.
     *
     * @return list<array{role:string,content:string}>
     */
    public static function contextFor(Vendor $vendor): array
    {
        $limit = max(2, (int) config('vendors.ai.chat_history_turns', 10));

        $rows = static::where('vendor_id', $vendor->id)
            ->orderByDesc('id')
            // One extra so a divider sitting exactly at the boundary is still seen.
            ->limit($limit + 1)
            ->get(['role', 'content']);

        $turns = [];
        foreach ($rows as $row) {
            if ($row->role === self::ROLE_DIVIDER) {
                break;
            }
            $turns[] = ['role' => $row->role, 'content' => (string) $row->content];
        }

        return array_slice(array_reverse($turns), -$limit);
    }

    /** The assistant's markdown as sanitised HTML; a user's turn stays plain text. */
    public function html(): string
    {
        if (! $this->isAssistant()) {
            return e((string) $this->content);
        }

        // Model output. Strip any HTML it emits rather than trusting a prompt rule to
        // have held, and never let it render a javascript: link.
        return Str::markdown((string) $this->content, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);
    }
}
