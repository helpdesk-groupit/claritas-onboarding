<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
  body { font-family:'Segoe UI',Arial,sans-serif; background:#f1f5f9; margin:0; padding:20px; }
  .email-wrap { max-width:620px; margin:0 auto; background:#fff; border-radius:12px; overflow:hidden; box-shadow:0 2px 12px rgba(0,0,0,0.08); }
  .header { background:linear-gradient(135deg,#991b1b,#dc2626); padding:32px 30px; text-align:center; }
  .header h1 { color:#fff; margin:0; font-size:22px; font-weight:700; }
  .header p  { color:rgba(255,255,255,0.85); margin:6px 0 0; font-size:14px; }
  .body { padding:30px; }
  .greeting { font-size:18px; font-weight:600; color:#1e293b; margin-bottom:12px; }
  .info-box { background:#eff6ff; border-left:4px solid #2563eb; border-radius:0 8px 8px 0; padding:16px 20px; margin:16px 0; font-size:14px; color:#1e40af; }
  .detail-row { display:flex; justify-content:space-between; padding:6px 0; font-size:14px; border-bottom:1px solid #e2e8f0; }
  .detail-row:last-child { border-bottom:none; }
  .detail-label { color:#64748b; }
  .detail-value { color:#1e293b; font-weight:600; }
  .remarks { background:#fff7ed; border-left:4px solid #f97316; border-radius:0 8px 8px 0; padding:16px 20px; margin:16px 0; font-size:14px; color:#9a3412; }
  .note { background:#f0fdf4; border-left:4px solid #16a34a; border-radius:0 8px 8px 0; padding:14px 18px; margin:16px 0; font-size:14px; color:#166534; }
  .footer { background:#f8fafc; padding:20px 30px; text-align:center; font-size:12px; color:#94a3b8; border-top:1px solid #e2e8f0; }
</style>
</head>
<body>
@php
    $period = \Carbon\Carbon::create($claim->year, $claim->month)->format('F Y');
    $company = $claim->employee->company ?? config('app.name');
    $action = $action ?? 'rejected';
    $isReversed = $action === 'reversed';
    $reason = $isReversed ? $claim->reverse_remarks : $claim->hr_remarks;
@endphp
<div class="email-wrap">

  <div class="header">
    <h1>Claim {{ $isReversed ? 'Reversed' : 'Rejected' }} by HR</h1>
    <p>{{ $claim->event ?: $period }} &mdash; For your information</p>
  </div>

  <div class="body">
    <div class="greeting">Dear {{ $manager->preferred_name ?? $manager->full_name }},</div>
    <p style="color:#475569;font-size:15px;line-height:1.6;">
      HR has {{ $isReversed ? 'reversed' : 'rejected' }} an expense claim from <strong>{{ $claim->employee->full_name }}</strong> that you previously approved.
    </p>

    <div class="note">
      <i>No action is needed from you.</i> {{ $claim->employee->preferred_name ?? $claim->employee->full_name }}
      can correct and resubmit the claim directly.
    </div>

    <div class="info-box">
      <div class="detail-row"><span class="detail-label">Claim No.</span> <span class="detail-value">{{ $claim->claim_number }}</span></div>
      @if($claim->event)<div class="detail-row"><span class="detail-label">Event</span> <span class="detail-value">{{ $claim->event }}</span></div>@endif
      <div class="detail-row"><span class="detail-label">Period</span> <span class="detail-value">{{ $period }}</span></div>
      <div class="detail-row"><span class="detail-label">Employee</span> <span class="detail-value">{{ $claim->employee->full_name }}</span></div>
      <div class="detail-row"><span class="detail-label">Total (w/ SST)</span> <span class="detail-value">RM {{ number_format($claim->total_with_gst, 2) }}</span></div>
    </div>

    @if($reason)
    <div class="remarks">
      <strong>HR's reason:</strong><br>
      {{ $reason }}
    </div>
    @endif
  </div>

  <div class="footer">
    This is an automated message from {{ $company }}.<br>
    Please do not reply directly to this email.
  </div>
</div>
</body>
</html>
