<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Files uploaded at ticket-creation time. Distinct from chat-message attachments
 * (which live on TicketMessage). These are the "supporting documents" for the
 * ticket itself — shown alongside the description on the show page.
 */
class TicketAttachment extends Model
{
    use HasFactory;

    protected $fillable = [
        'ticket_id', 'file_path', 'original_name', 'mime', 'size', 'is_image',
    ];

    protected $casts = [
        'is_image' => 'boolean',
        'size'     => 'integer',
    ];

    public function ticket()
    {
        return $this->belongsTo(Ticket::class);
    }

    /** Secure URL via SecureFileController (auth-gated). */
    public function url(): string
    {
        return route('secure.file', $this->file_path);
    }

    /** Human-readable size (e.g. "2.4 MB"). */
    public function humanSize(): string
    {
        $bytes = (int) $this->size;
        if ($bytes < 1024) return $bytes . ' B';
        if ($bytes < 1024 * 1024) return round($bytes / 1024, 1) . ' KB';
        return round($bytes / (1024 * 1024), 1) . ' MB';
    }
}
