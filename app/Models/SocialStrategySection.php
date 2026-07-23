<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One generated section of a social strategy. Editable in place and
 * regenerable individually; the "LIVE-SOURCED" badge is driven by
 * is_live_sourced (set when the section ran with web search).
 */
class SocialStrategySection extends Model
{
    public const STATUS_WAIT = 'wait';

    public const STATUS_RUNNING = 'running';

    public const STATUS_OK = 'ok';

    public const STATUS_ERROR = 'error';

    protected $fillable = [
        'social_strategy_id', 'section_key', 'position', 'title', 'content',
        'is_live_sourced', 'status', 'error', 'tokens_input', 'tokens_output',
        'edited_at', 'generated_at',
    ];

    protected $casts = [
        'position' => 'integer',
        'is_live_sourced' => 'boolean',
        'tokens_input' => 'integer',
        'tokens_output' => 'integer',
        'edited_at' => 'datetime',
        'generated_at' => 'datetime',
    ];

    public function strategy(): BelongsTo
    {
        return $this->belongsTo(SocialStrategy::class, 'social_strategy_id');
    }
}
