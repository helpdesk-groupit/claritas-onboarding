<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Files attached to a chat message. Supports multi-file uploads (one row per
 * file, all linked to the same TicketMessage).
 */
class TicketMessageAttachment extends Model
{
    use HasFactory;

    protected $fillable = [
        'message_id', 'file_path', 'original_name', 'mime', 'size', 'is_image',
    ];

    protected $casts = [
        'is_image' => 'boolean',
        'size'     => 'integer',
    ];

    public function message()
    {
        return $this->belongsTo(TicketMessage::class, 'message_id');
    }

    /** Secure URL via SecureFileController (auth-gated). */
    public function url(): string
    {
        return route('secure.file', $this->file_path);
    }
}
