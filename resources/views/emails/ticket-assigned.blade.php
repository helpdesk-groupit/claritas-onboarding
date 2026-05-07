<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
  body { font-family:'Segoe UI',Arial,sans-serif; background:#f1f5f9; margin:0; padding:20px; }
  .email-wrap { max-width:620px; margin:0 auto; background:#fff; border-radius:12px; overflow:hidden; box-shadow:0 2px 12px rgba(0,0,0,0.08); }
  .header { background:linear-gradient(135deg,#0f766e,#14b8a6); padding:32px 30px; text-align:center; }
  .header h1 { color:#fff; margin:0; font-size:22px; font-weight:700; }
  .header p  { color:rgba(255,255,255,0.85); margin:6px 0 0; font-size:14px; }
  .body { padding:30px; }
  .greeting { font-size:18px; font-weight:600; color:#1e293b; margin-bottom:12px; }
  .info-box { background:#f0fdfa; border-left:4px solid #14b8a6; border-radius:0 8px 8px 0; padding:16px 20px; margin:16px 0; font-size:14px; color:#115e59; }
  .meta-row { padding:5px 0; font-size:13px; color:#334155; }
  .meta-row strong { display:inline-block; min-width:110px; color:#1e293b; }
  .btn-cta { display:inline-block; background:#14b8a6; color:#fff !important; text-decoration:none; padding:11px 22px; border-radius:8px; font-size:14px; font-weight:600; margin-top:14px; }
  .footer { background:#f8fafc; padding:20px 30px; text-align:center; font-size:12px; color:#94a3b8; border-top:1px solid #e2e8f0; }
</style>
</head>
<body>
<div class="email-wrap">

  <div class="header">
    <h1>Ticket Assigned to You</h1>
    <p>{{ $ticket->ticket_number }}</p>
  </div>

  <div class="body">
    <div class="greeting">Hello {{ $assignee->name }},</div>

    <p style="color:#475569;font-size:15px;line-height:1.6;">
      A ticket in the <strong>{{ $ticket->department }}</strong> department has been assigned to you.
      Please review and respond as soon as possible.
    </p>

    <div class="info-box">
      <div class="meta-row"><strong>Subject:</strong> {{ $ticket->subject }}</div>
      <div class="meta-row"><strong>Priority:</strong> {{ $ticket->priority }}</div>
      <div class="meta-row"><strong>Created by:</strong> {{ $ticket->creator?->name ?? 'Unknown' }}</div>
      <div class="meta-row"><strong>Created:</strong> {{ $ticket->created_at?->format('d M Y, g:i a') }}</div>
    </div>

    <p style="color:#475569;font-size:14px;line-height:1.6;">
      <strong>Description:</strong><br>
      {{ \Illuminate\Support\Str::limit($ticket->description, 350) }}
    </p>

    <a href="{{ route('tickets.show', $ticket) }}" class="btn-cta">Open Ticket</a>

    <p style="font-size:13px;color:#94a3b8;margin-top:18px;">
      Reply directly inside the ticket conversation to chat with the requester.
    </p>
  </div>

  <div class="footer">
    © {{ date('Y') }} Employee Portal &bull; Automated notification. Do not reply.
  </div>
</div>
</body>
</html>
