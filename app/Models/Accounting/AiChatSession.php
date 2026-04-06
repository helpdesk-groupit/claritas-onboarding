<?php

namespace App\Models\Accounting;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class AiChatSession extends Model
{
    protected $table = 'acc_ai_chat_sessions';

    protected $fillable = ['company', 'user_id', 'title', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function user()     { return $this->belongsTo(User::class); }
    public function messages() { return $this->hasMany(AiChatMessage::class, 'session_id')->orderBy('created_at'); }
}
