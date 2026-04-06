<?php

namespace App\Models\Accounting;

use Illuminate\Database\Eloquent\Model;

class AiChatMessage extends Model
{
    protected $table = 'acc_ai_chat_messages';

    protected $fillable = ['session_id', 'role', 'content', 'metadata', 'tokens_used'];

    protected $casts = ['metadata' => 'array'];

    public function session() { return $this->belongsTo(AiChatSession::class, 'session_id'); }
}
