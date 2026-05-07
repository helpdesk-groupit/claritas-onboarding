<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TicketMessage extends Model
{
    use HasFactory;

    protected $fillable = [
        'ticket_id', 'user_id', 'message',
        'attachment_path', 'attachment_original_name', 'attachment_mime',
    ];

    public function ticket()
    {
        return $this->belongsTo(Ticket::class);
    }

    public function sender()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Multi-file attachments uploaded with this message (new model).
     * Older messages may have a single legacy attachment via the
     * attachment_path / attachment_original_name / attachment_mime
     * columns directly on this row — see hasAttachment().
     */
    public function attachments()
    {
        return $this->hasMany(TicketMessageAttachment::class, 'message_id');
    }

    /** True if this message has a file attachment. */
    public function hasAttachment(): bool
    {
        return !empty($this->attachment_path);
    }

    /** Whether the attachment is an image (used to render inline preview). */
    public function isImageAttachment(): bool
    {
        return $this->hasAttachment() && str_starts_with($this->attachment_mime ?? '', 'image/');
    }

    /** Secure URL for downloading the attachment via SecureFileController. */
    public function attachmentUrl(): ?string
    {
        if (!$this->hasAttachment()) {
            return null;
        }
        return route('secure.file', $this->attachment_path);
    }
}
