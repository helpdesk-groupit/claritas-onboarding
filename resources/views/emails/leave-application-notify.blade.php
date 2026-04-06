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
  .detail-row { display:flex; justify-content:space-between; padding:6px 0; font-size:14px; border-bottom:1px solid #e2e8f0; }
  .detail-row:last-child { border-bottom:none; }
  .detail-label { color:#64748b; }
  .detail-value { color:#1e293b; font-weight:600; }
  .btn { display:inline-block; background:linear-gradient(135deg,#2563eb,#1d4ed8); color:#fff; padding:12px 28px; border-radius:8px; text-decoration:none; font-weight:600; font-size:14px; margin-top:16px; }
  .badge-pending { display:inline-block; background:#fef3c7; color:#92400e; padding:4px 12px; border-radius:20px; font-size:12px; font-weight:600; }
  .footer { background:#f8fafc; padding:20px 30px; text-align:center; font-size:12px; color:#94a3b8; border-top:1px solid #e2e8f0; }
</style>
</head>
<body>
@php
    $company = $employee->company ?? config('app.name');
    $leaveName = $application->leaveType?->name ?? 'Leave';
@endphp
<div class="email-wrap">

  <div class="header">
    <h1>New Leave Application</h1>
    <p>Action Required — Pending Approval</p>
  </div>

  <div class="body">
    @if($recipientType === 'manager')
    <div class="greeting">Dear Manager,</div>
    <p style="color:#475569;font-size:15px;line-height:1.6;">
      Your team member <strong>{{ $employee->preferred_name ?? $employee->full_name }}</strong>
      has submitted a leave application requiring your approval.
    </p>
    @else
    <div class="greeting">Dear HR Team,</div>
    <p style="color:#475569;font-size:15px;line-height:1.6;">
      <strong>{{ $employee->preferred_name ?? $employee->full_name }}</strong>
      ({{ $employee->designation ?? 'Employee' }}{{ $employee->department ? ', '.$employee->department : '' }})
      has submitted a leave application.
    </p>
    @endif

    <div class="info-box">
      <div class="detail-row">
        <span class="detail-label">Employee</span>
        <span class="detail-value">{{ $employee->full_name }}</span>
      </div>
      <div class="detail-row">
        <span class="detail-label">Company</span>
        <span class="detail-value">{{ $employee->company ?? '—' }}</span>
      </div>
      <div class="detail-row">
        <span class="detail-label">Leave Type</span>
        <span class="detail-value">{{ $leaveName }}</span>
      </div>
      <div class="detail-row">
        <span class="detail-label">From</span>
        <span class="detail-value">{{ $application->start_date->format('d M Y (l)') }}</span>
      </div>
      <div class="detail-row">
        <span class="detail-label">To</span>
        <span class="detail-value">{{ $application->end_date->format('d M Y (l)') }}</span>
      </div>
      <div class="detail-row">
        <span class="detail-label">Duration</span>
        <span class="detail-value">{{ $application->total_days }} day(s){{ $application->is_half_day ? ' (Half Day — '.ucfirst($application->half_day_period).')' : '' }}</span>
      </div>
      @if($application->reason)
      <div class="detail-row">
        <span class="detail-label">Reason</span>
        <span class="detail-value">{{ $application->reason }}</span>
      </div>
      @endif
      <div class="detail-row">
        <span class="detail-label">Status</span>
        <span class="detail-value"><span class="badge-pending">Pending Approval</span></span>
      </div>
    </div>

    <p style="color:#475569;font-size:14px;line-height:1.6;">
      Please log in to the Employee Portal to review and action this request.
    </p>

    <div style="text-align:center;">
      @if($recipientType === 'manager')
      <a href="{{ url('/my/team-leave') }}" class="btn">Review Leave Request</a>
      @else
      <a href="{{ url('/hr/leave?status=pending') }}" class="btn">Review Leave Request</a>
      @endif
    </div>

    <p style="font-size:13px;color:#94a3b8;margin-top:20px;">
      Under the Employment Act 1955 (Malaysia), employees are entitled to take annual leave, sick leave,
      and other statutory leave. Please review and respond to this request promptly.
    </p>
  </div>

  <div class="footer">
    &copy; {{ date('Y') }} {{ $company }} &bull;
    Automated notification from the Employee Portal. Do not reply.
  </div>
</div>
</body>
</html>
