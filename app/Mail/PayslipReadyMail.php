<?php

namespace App\Mail;

use App\Models\Employee;
use App\Models\PayRun;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PayslipReadyMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Employee $employee,
        public PayRun $payRun,
    ) {}

    public function envelope(): Envelope
    {
        $month = \Carbon\Carbon::create($this->payRun->year, $this->payRun->month)->format('F Y');
        return new Envelope(
            subject: "Your Payslip for {$month} is Ready"
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.payslip-ready', with: [
            'employee' => $this->employee,
            'payRun'   => $this->payRun,
        ]);
    }
}
