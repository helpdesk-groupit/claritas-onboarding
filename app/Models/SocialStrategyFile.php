<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A knowledge-base upload attached to a social strategy. Stored on the private
 * `local` disk via AttachmentProcessor and scanned by the global malware
 * middleware; only `clean` files are ever read back into an AI call.
 */
class SocialStrategyFile extends Model
{
    public const SCAN_PENDING = 'pending';

    public const SCAN_CLEAN = 'clean';

    public const SCAN_INFECTED = 'infected';

    protected $fillable = [
        'social_strategy_id', 'uploaded_by', 'original_name', 'file_path',
        'mime', 'size', 'is_image', 'kind', 'extracted_text',
        'scan_status', 'scanned_at',
    ];

    protected $casts = [
        'is_image' => 'boolean',
        'size' => 'integer',
        'scanned_at' => 'datetime',
    ];

    public function strategy(): BelongsTo
    {
        return $this->belongsTo(SocialStrategy::class, 'social_strategy_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** Safe to feed into an AI call / show to the user. */
    public function isReadable(): bool
    {
        return $this->scan_status === self::SCAN_CLEAN;
    }

    /** True for binary kinds (pdf/image) that go in as base64 document/image blocks. */
    public function isBinary(): bool
    {
        return in_array($this->kind, ['pdf', 'image'], true);
    }
}
