<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
  body { font-family:'Segoe UI',Arial,sans-serif; background:#f1f5f9; margin:0; padding:20px; }
  .email-wrap { max-width:620px; margin:0 auto; background:#fff; border-radius:12px; overflow:hidden; box-shadow:0 2px 12px rgba(0,0,0,0.08); }
  .header { background:linear-gradient(135deg,#92400e,#d97706); padding:32px 30px; text-align:center; }
  .header h1 { color:#fff; margin:0; font-size:22px; font-weight:700; }
  .header p  { color:rgba(255,255,255,0.85); margin:6px 0 0; font-size:14px; }
  .body { padding:30px; }
  .greeting { font-size:18px; font-weight:600; color:#1e293b; margin-bottom:12px; }
  .info-box { background:#fffbeb; border-left:4px solid #d97706; border-radius:0 8px 8px 0; padding:16px 20px; margin:16px 0; font-size:14px; color:#92400e; }
  .detail-row { display:flex; justify-content:space-between; padding:6px 0; font-size:14px; border-bottom:1px solid #e2e8f0; }
  .detail-row:last-child { border-bottom:none; }
  .detail-label { color:#64748b; }
  .detail-value { color:#1e293b; font-weight:600; }
  .btn { display:inline-block; background:linear-gradient(135deg,#d97706,#b45309); color:#fff; padding:12px 28px; border-radius:8px; text-decoration:none; font-weight:600; font-size:14px; margin-top:16px; }
  .footer { background:#f8fafc; padding:20px 30px; text-align:center; font-size:12px; color:#94a3b8; border-top:1px solid #e2e8f0; }
</style>
</head>
<body>
@php
    $period = \Carbon\Carbon::create($claim->year, $claim->month)->format('F Y');
    $event = $claim->eventName();
    $company = $employee->company ?? config('app.name');
    $v = $cutoffDay % 100;
    $suffix = ['th','st','nd','rd'][($v - 20) % 10] ?? ['th','st','nd','rd'][$v] ?? 'th';
    $cutoffLabel = $cutoffDay.$suffix;
@endphp
<div class="email-wrap">

  <div class="header">
    <h1>Claim Auto-Submitted</h1>
    <p>{{ $event ? $event.' — '.$period : $period }}</p>
  </div>

  <div class="body">
    <div class="greeting">Dear {{ $employee->preferred_name ?? $employee->full_name }},</div>
    <p style="color:#475569;font-size:15px;line-height:1.6;">
      It reached the <strong>{{ $cutoffLabel }} submission cutoff</strong> and this draft claim was still unsubmitted, so the system
      <strong>auto-submitted it for you</strong> to {{ $managerName ? $managerName : 'your approving manager' }}. Nothing was lost —
      it is now in the approval flow.
    </p>

    <div class="info-box">
      Because it was submitted on the cutoff, it may be <strong>processed together with next month's claims</strong>.
    </div>

    <div class="info-box" style="background:#eff6ff;border-left-color:#2563eb;color:#1e40af;">
      <div class="detail-row"><span class="detail-label">Claim No.</span> <span class="detail-value">{{ $claim->claim_number }}</span></div>
      @if($event)<div class="detail-row"><span class="detail-label">Event</span> <span class="detail-value">{{ $event }}</span></div>@endif
      <div class="detail-row"><span class="detail-label">Period</span> <span class="detail-value">{{ $period }}</span></div>
      <div class="detail-row"><span class="detail-label">Approver</span> <span class="detail-value">{{ $managerName ?? '-' }}</span></div>
      <div class="detail-row"><span class="detail-label">Items</span> <span class="detail-value">{{ $claim->item_count }} item(s)</span></div>
      <div class="detail-row"><span class="detail-label">Total (w/ SST)</span> <span class="detail-value">RM {{ number_format($claim->total_with_gst, 2) }}</span></div>
    </div>

    @include('emails.partials._button', ['url' => route('login'), 'label' => 'View Claim →'])
  </div>

  <div class="footer">
    This is an automated message from {{ $company }}.<br>
    Please do not reply directly to this email.
  </div>
</div>
</body>
</html>
