<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
  body { font-family:'Segoe UI',Arial,sans-serif; background:#f1f5f9; margin:0; padding:20px; }
  .email-wrap { max-width:620px; margin:0 auto; background:#fff; border-radius:12px; overflow:hidden; box-shadow:0 2px 12px rgba(0,0,0,0.08); }
  .header { background:linear-gradient(135deg,#0369a1,#0ea5e9); padding:28px 30px; text-align:center; }
  .header h1 { color:#fff; margin:0; font-size:20px; font-weight:700; }
  .header p  { color:rgba(255,255,255,0.85); margin:6px 0 0; font-size:13px; }
  .body { padding:30px; }
  .greeting { font-size:17px; font-weight:600; color:#1e293b; margin-bottom:8px; }
  .lead { color:#475569; font-size:14px; line-height:1.6; margin:0 0 18px; }
  .meta-box { background:#f0f9ff; border-left:4px solid #0ea5e9; border-radius:0 8px 8px 0; padding:14px 18px; margin:14px 0 18px; font-size:13px; color:#0c4a6e; }
  .meta-row { padding:3px 0; }
  .meta-row strong { display:inline-block; min-width:90px; color:#0f172a; }
  .preview-box { background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:16px 18px; margin:6px 0 20px; }
  .preview-from { font-size:12px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.4px; margin-bottom:6px; }
  .preview-text { color:#1e293b; font-size:14px; line-height:1.55; white-space:pre-wrap; word-wrap:break-word; margin:0; }
  .preview-attach { margin-top:8px; padding-top:8px; border-top:1px dashed #cbd5e1; font-size:12px; color:#64748b; }
  .preview-attach i { margin-right:4px; }
  .btn-cta { display:inline-block; background:#2563eb; color:#fff !important; text-decoration:none; padding:12px 24px; border-radius:8px; font-size:14px; font-weight:600; }
  .btn-row { text-align:center; margin:10px 0 14px; }
  .hint { font-size:12px; color:#94a3b8; margin-top:14px; line-height:1.5; }
  .footer { background:#f8fafc; padding:18px 30px; text-align:center; font-size:11px; color:#94a3b8; border-top:1px solid #e2e8f0; }
</style>
</head>
<body>
@php
    $preview = trim((string) $ticketMessage->message);
    $hasText = $preview !== '';
    $newAttachCount = $ticketMessage->attachments->count();
    $legacyHasAttachment = method_exists($ticketMessage, 'hasAttachment') && $ticketMessage->hasAttachment();
    $attachmentCount = $newAttachCount + ($legacyHasAttachment ? 1 : 0);
    $ticketUrl = route('tickets.show', $ticket);
@endphp
<div class="email-wrap">

  <div class="header">
    <h1>💬 New Message on Your Ticket</h1>
    <p>{{ $ticket->ticket_number }} &mdash; {{ $ticket->department }}</p>
  </div>

  <div class="body">
    <div class="greeting">Hello {{ $recipient->name ?? 'there' }},</div>
    <p class="lead">
      <strong>{{ $sender->name }}</strong> has posted a new message on the ticket
      "<strong>{{ $ticket->subject }}</strong>". Please visit the Employee Portal to review and reply.
    </p>

    <div class="meta-box">
      <div class="meta-row"><strong>Ticket:</strong> {{ $ticket->ticket_number }}</div>
      <div class="meta-row"><strong>Subject:</strong> {{ $ticket->subject }}</div>
      <div class="meta-row"><strong>Department:</strong> {{ $ticket->department }}</div>
      <div class="meta-row"><strong>Status:</strong> {{ $ticket->status }}</div>
      <div class="meta-row"><strong>From:</strong> {{ $sender->name }}</div>
    </div>

    <div class="preview-box">
      <div class="preview-from">{{ $sender->name }} wrote:</div>
      @if($hasText)
        <p class="preview-text">{{ \Illuminate\Support\Str::limit($preview, 600) }}</p>
      @else
        <p class="preview-text" style="font-style:italic;color:#64748b;">(no text — see attachment{{ $attachmentCount > 1 ? 's' : '' }} on the portal)</p>
      @endif
      @if($attachmentCount > 0)
        <div class="preview-attach">
          <i>📎</i> {{ $attachmentCount }} attachment{{ $attachmentCount > 1 ? 's' : '' }} included &mdash; visible on the portal.
        </div>
      @endif
    </div>

    <div class="btn-row">
      <!--[if mso]>
      <v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word"
        href="{{ $ticketUrl }}"
        style="height:44px;v-text-anchor:middle;width:240px;" arcsize="10%"
        fillcolor="#2563eb" strokecolor="#2563eb">
        <w:anchorlock/>
        <center style="color:#ffffff;font-family:Arial,sans-serif;font-size:14px;font-weight:bold;">
          View Message in Portal
        </center>
      </v:roundrect>
      <![endif]-->
      <!--[if !mso]><!-->
      <a href="{{ $ticketUrl }}" class="btn-cta">View Message in Portal &rarr;</a>
      <!--<![endif]-->
    </div>

    <p class="hint">
      You're receiving this because you raised this ticket, are the assigned PIC, or manage the
      <strong>{{ $ticket->department }}</strong> department. Replies must be posted in the Employee Portal
      (this inbox doesn't track responses) &mdash; do not reply to this email.
    </p>
  </div>

  <div class="footer">
    © {{ date('Y') }} Employee Portal &bull; Automated notification.
  </div>
</div>
</body>
</html>
