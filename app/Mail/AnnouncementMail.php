<?php

namespace App\Mail;

use App\Models\Announcement;
use App\Models\Employee;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AnnouncementMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Announcement $announcement,
        public Employee $employee,
    ) {}

    public function envelope(): Envelope
    {
        $companies = $this->announcement->companies ?? [];
        $companyLabel = !empty($companies) ? implode(', ', $companies) : 'All Companies';
        return new Envelope(
            subject: "New Announcement: {$this->announcement->title} — {$companyLabel}"
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.announcement', with: [
            'announcement' => $this->announcement,
            'employee'     => $this->employee,
        ]);
    }
}
