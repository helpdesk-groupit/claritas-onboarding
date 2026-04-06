<?php

namespace App\Mail;

use App\Models\EaForm;
use App\Models\Employee;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class EaFormReadyMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Employee $employee,
        public EaForm $eaForm,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Your EA Form (Borang EA) for {$this->eaForm->year} is Ready"
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.ea-form-ready', with: [
            'employee' => $this->employee,
            'eaForm'   => $this->eaForm,
        ]);
    }
}
