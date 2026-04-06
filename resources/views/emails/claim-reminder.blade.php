<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
  body { font-family:'Segoe UI',Arial,sans-serif; background:#f1f5f9; margin:0; padding:20px; }
  .email-wrap { max-width:620px; margin:0 auto; background:#fff; border-radius:12px; overflow:hidden; box-shadow:0 2px 12px rgba(0,0,0,0.08); }
  .header { background:linear-gradient(135deg,#1e3a5f,#2563eb); padding:32px 30px; text-align:center; }
  .header h1 { color:#fff; margin:0; font-size:22px; font-weight:700; }
  .header p  { color:rgba(255,255,255,0.8); margin:6px 0 0; font-size:14px; }
  .body { padding:30px; }
  .greeting { font-size:18px; font-weight:600; color:#1e293b; margin-bottom:12px; }
  .info-box { background:#eff6ff; border-left:4px solid #2563eb; border-radius:0 8px 8px 0; padding:16px 20px; margin:16px 0; font-size:14px; color:#1e40af; }
  .btn { display:inline-block; background:linear-gradient(135deg,#2563eb,#1d4ed8); color:#fff; padding:12px 28px; border-radius:8px; text-decoration:none; font-weight:600; font-size:14px; margin-top:16px; }
  .footer { background:#f8fafc; padding:20px 30px; text-align:center; font-size:12px; color:#94a3b8; border-top:1px solid #e2e8f0; }
</style>
</head>
<body>
@php
    $period = \Carbon\Carbon::create($year, $month)->format('F Y');
    $company = $employee->company ?? config('app.name');
@endphp
<div class="email-wrap">

  <div class="header">
    <h1>Expense Claim Reminder</h1>
    <p>{{ $period }} &mdash; Submission Deadline Approaching</p>
  </div>

  <div class="body">
    <div class="greeting">Dear {{ $employee->preferred_name ?? $employee->full_name }},</div>
    <p style="color:#475569;font-size:15px;line-height:1.6;">
      This is a friendly reminder to submit your expense claims for <strong>{{ $period }}</strong>.
    </p>

    <div class="info-box">
      <strong>Submission Deadline:</strong> {{ $deadline }}<br><br>
      Claims submitted after this date will be processed in the next month's cycle.
      Please ensure all claims are properly signed by your reporting manager before submission.
    </div>

    <p style="text-align:center;">
      <a href="{{ route('login') }}" class="btn">Submit Claims →</a>
    </p>
  </div>

  <div class="footer">
    This is an automated message from {{ $company }}.<br>
    Please do not reply directly to this email.
  </div>
</div>
</body>
</html>
