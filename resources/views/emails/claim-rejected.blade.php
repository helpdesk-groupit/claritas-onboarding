<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
  body { font-family:'Segoe UI',Arial,sans-serif; background:#f1f5f9; margin:0; padding:20px; }
  .email-wrap { max-width:620px; margin:0 auto; background:#fff; border-radius:12px; overflow:hidden; box-shadow:0 2px 12px rgba(0,0,0,0.08); }
  .header { background:linear-gradient(135deg,#991b1b,#dc2626); padding:32px 30px; text-align:center; }
  .header h1 { color:#fff; margin:0; font-size:22px; font-weight:700; }
  .header p  { color:rgba(255,255,255,0.8); margin:6px 0 0; font-size:14px; }
  .body { padding:30px; }
  .greeting { font-size:18px; font-weight:600; color:#1e293b; margin-bottom:12px; }
  .info-box { background:#fef2f2; border-left:4px solid #dc2626; border-radius:0 8px 8px 0; padding:16px 20px; margin:16px 0; font-size:14px; color:#991b1b; }
  .detail-row { display:flex; justify-content:space-between; padding:6px 0; font-size:14px; border-bottom:1px solid #e2e8f0; }
  .detail-row:last-child { border-bottom:none; }
  .detail-label { color:#64748b; }
  .detail-value { color:#1e293b; font-weight:600; }
  .remarks { background:#fff7ed; border-left:4px solid #f97316; border-radius:0 8px 8px 0; padding:16px 20px; margin:16px 0; font-size:14px; color:#9a3412; }
  .btn { display:inline-block; background:linear-gradient(135deg,#2563eb,#1d4ed8); color:#fff; padding:12px 28px; border-radius:8px; text-decoration:none; font-weight:600; font-size:14px; margin-top:16px; }
  .footer { background:#f8fafc; padding:20px 30px; text-align:center; font-size:12px; color:#94a3b8; border-top:1px solid #e2e8f0; }
</style>
</head>
<body>
@php
    $period = \Carbon\Carbon::create($claim->year, $claim->month)->format('F Y');
    $company = $employee->company ?? config('app.name');
    $by = $rejectorType === 'manager' ? 'Reporting Manager' : 'HR';
    $remarks = $rejectorType === 'manager' ? $claim->manager_remarks : $claim->hr_remarks;
@endphp
<div class="email-wrap">

  <div class="header">
    <h1>Expense Claim Rejected</h1>
    <p>{{ $period }} &mdash; Rejected by {{ $by }}</p>
  </div>

  <div class="body">
    <div class="greeting">Dear {{ $employee->preferred_name ?? $employee->full_name }},</div>
    <p style="color:#475569;font-size:15px;line-height:1.6;">
      Your expense claim for <strong>{{ $period }}</strong> has been rejected by your {{ strtolower($by) }}.
      Please review the remarks below, make the necessary corrections, and resubmit.
    </p>

    <div class="info-box">
      <div class="detail-row"><span class="detail-label">Claim No.</span> <span class="detail-value">{{ $claim->claim_number }}</span></div>
      <div class="detail-row"><span class="detail-label">Total (w/ GST)</span> <span class="detail-value">RM {{ number_format($claim->total_with_gst, 2) }}</span></div>
    </div>

    @if($remarks)
    <div class="remarks">
      <strong>Remarks from {{ $by }}:</strong><br>
      {{ $remarks }}
    </div>
    @endif

    <p style="text-align:center;">
      <a href="{{ route('login') }}" class="btn">Edit & Resubmit →</a>
    </p>
  </div>

  <div class="footer">
    This is an automated message from {{ $company }}.<br>
    Please do not reply directly to this email.
  </div>
</div>
</body>
</html>
