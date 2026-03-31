<?php

namespace App\Mail;

use App\Models\Onboarding;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CalendarInvite extends Mailable
{
    use Queueable, SerializesModels;

    public string $icsContent;

    public function __construct(public Onboarding $onboarding, public string $recipientName)
    {
        $p    = $onboarding->personalDetail;
        $w    = $onboarding->workDetail;
        $prov = $onboarding->assetProvisioning;

        $startDate = $w?->start_date ?? now()->toDateString();
        $uid       = 'claritas-onboarding-' . $onboarding->id . '@claritas.asia';
        $dtStart   = date('Ymd', strtotime($startDate));
        $dtStamp   = gmdate('Ymd\THis\Z');

        $name      = $p?->full_name ?? 'New Hire';
        $preferred = $p?->preferred_name ? " ({$p->preferred_name})" : '';
        $position  = $w?->designation ?? 'New Employee';
        $company   = $w?->company ?? 'Claritas Asia Sdn. Bhd.';
        $dept      = $w?->department ?? '—';
        $manager   = $w?->reporting_manager ?? '—';
        $managerEmail = $w?->reporting_manager_email ?? '—';
        $compEmail = $w?->company_email ?? '—';
        $googleId  = $w?->google_id ?? '—';
        $location  = $w?->office_location ?? 'Kuala Lumpur HQ';

        // Build asset provisioning lines
        $assets = [];
        if ($prov) {
            if ($prov->laptop_provision)    $assets[] = 'Laptop';
            if ($prov->monitor_set)         $assets[] = 'Monitor Set';
            if ($prov->converter)           $assets[] = 'Converter';
            if ($prov->company_phone)       $assets[] = 'Company Phone';
            if ($prov->sim_card)            $assets[] = 'SIM Card';
            if ($prov->access_card_request) $assets[] = 'Access Card';
            if ($prov->office_keys)         $assets[] = 'Office Keys: ' . $prov->office_keys;
            if ($prov->others)              $assets[] = 'Others: ' . $prov->others;
        }
        $assetStr = empty($assets) ? 'None' : implode(', ', $assets);

        $desc = implode('\n', [
            "ONBOARDING DETAILS",
            "==================",
            "Full Name: {$name}{$preferred}",
            "Company: {$company}",
            "Department: {$dept}",
            "Designation: {$position}",
            "Reporting Manager: {$manager}",
            "Reporting Manager Email: {$managerEmail}",
            "Start Date: " . date('d M Y', strtotime($startDate)),
            "Company Email: {$compEmail}",
            "Google ID: {$googleId}",
            " ",
            "ASSET PROVISIONING",
            "==================",
            $assetStr,
        ]);

        $this->icsContent = implode("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Claritas Asia//Employee Portal//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:REQUEST',
            'BEGIN:VEVENT',
            "UID:{$uid}",
            "DTSTAMP:{$dtStamp}",
            "DTSTART;VALUE=DATE:{$dtStart}",
            "DTEND;VALUE=DATE:{$dtStart}",
            "SUMMARY:New Hire Onboarding — {$name}",
            "DESCRIPTION:{$desc}",
            "LOCATION:{$location}",
            'STATUS:CONFIRMED',
            'SEQUENCE:0',
            'END:VEVENT',
            'END:VCALENDAR',
        ]);
    }

    public function envelope(): Envelope
    {
        $w    = $this->onboarding->workDetail;
        $name = $this->onboarding->personalDetail?->full_name ?? 'New Hire';
        $date = $w?->start_date ? $w->start_date->format('d M Y') : '';
        return new Envelope(subject: "Onboarding Calendar Invite — {$name} starts {$date}");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.calendar-invite');
    }

    public function attachments(): array
    {
        return [
            \Illuminate\Mail\Mailables\Attachment::fromData(
                fn() => $this->icsContent,
                'onboarding-invite.ics'
            )->withMime('text/calendar'),
        ];
    }
}
