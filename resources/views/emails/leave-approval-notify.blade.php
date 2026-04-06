<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
  body { font-family:'Segoe UI',Arial,sans-serif; background:#f1f5f9; margin:0; padding:20px; }
  .email-wrap { max-width:620px; margin:0 auto; background:#fff; border-radius:12px; overflow:hidden; box-shadow:0 2px 12px rgba(0,0,0,0.08); }
  .header-approved { background:linear-gradient(135deg,#065f46,#10b981); padding:32px 30px; text-align:center; }
  .header-rejected { background:linear-gradient(135deg,#991b1b,#ef4444); padding:32px 30px; text-align:center; }
  .header h1 { color:#fff; margin:0; font-size:22px; font-weight:700; }
  .header h1, .header-approved h1, .header-rejected h1 { color:#fff; margin:0; font-size:22px; font-weight:700; }
  .header p, .header-approved p, .header-rejected p { color:rgba(255,255,255,0.8); margin:6px 0 0; font-size:14px; }
  .body { padding:30px; }
  .greeting { font-size:18px; font-weight:600; color:#1e293b; margin-bottom:12px; }
  .info-box { border-radius:0 8px 8px 0; padding:16px 20px; margin:16px 0; font-size:14px; }
  .info-box-approved { background:#ecfdf5; border-left:4px solid #10b981; color:#065f46; }
  .info-box-rejected { background:#fef2f2; border-left:4px solid #ef4444; color:#991b1b; }
  .detail-row { display:flex; justify-content:space-between; padding:6px 0; font-size:14px; border-bottom:1px solid #e2e8f0; }
  .detail-row:last-child { border-bottom:none; }
  .detail-label { color:#64748b; }
  .detail-value { color:#1e293b; font-weight:600; }
  .btn { display:inline-block; background:linear-gradient(135deg,#2563eb,#1d4ed8); color:#fff; padding:12px 28px; border-radius:8px; text-decoration:none; font-weight:600; font-size:14px; margin-top:16px; }
  .badge-approved { display:inline-block; background:#dcfce7; color:#166534; padding:4px 12px; border-radius:20px; font-size:12px; font-weight:600; }
  .badge-rejected { display:inline-block; background:#fee2e2; color:#991b1b; padding:4px 12px; border-radius:20px; font-size:12px; font-weight:600; }
  .footer { background:#f8fafc; padding:20px 30px; text-align:center; font-size:12px; color:#94a3b8; border-top:1px solid #e2e8f0; }
</style>
</head>
<body>
@php
    $company = $employee->company ?? config('app.name');
    $leaveName = $application->leaveType?->name ?? 'Leave';
    $isApproved = $action === 'approved';
@endphp
<div class="email-wrap">

  <div class="{{ $isApproved ? 'header-approved' : 'header-rejected' }}">
    <h1>Leave {{ ucfirst($action) }}</h1>
    <p>{{ $leaveName }} — {{ $application->start_date->format('d M Y') }} to {{ $application->end_date->format('d M Y') }}</p>
  </div>

  <div class="body">
    <div class="greeting">Dear {{ $employee->preferred_name ?? $employee->full_name }},</div>

    <p style="color:#475569;font-size:15px;line-height:1.6;">
      Your leave application has been <strong>{{ $action }}</strong> by
      <strong>{{ $actorName }}</strong> ({{ ucfirst($actorRole) }}).
    </p>

    <div class="info-box {{ $isApproved ? 'info-box-approved' : 'info-box-rejected' }}">
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
        <span class="detail-value">{{ $application->total_days }} day(s){{ $application->is_half_day ? ' (Half Day)' : '' }}</span>
      </div>
      <div class="detail-row">
        <span class="detail-label">Status</span>
        <span class="detail-value">
          <span class="{{ $isApproved ? 'badge-approved' : 'badge-rejected' }}">{{ ucfirst($action) }}</span>
        </span>
      </div>
      <div class="detail-row">
        <span class="detail-label">{{ ucfirst($actorRole) }}</span>
        <span class="detail-value">{{ $actorName }}</span>
      </div>
      @if(!$isApproved && $application->rejection_reason)
      <div class="detail-row">
        <span class="detail-label">Reason for Rejection</span>
        <span class="detail-value">{{ $application->rejection_reason }}</span>
      </div>
      @endif
      @if(!$isApproved && $application->manager_remarks)
      <div class="detail-row">
        <span class="detail-label">Manager Remarks</span>
        <span class="detail-value">{{ $application->manager_remarks }}</span>
      </div>
      @endif
    </div>

    @if($isApproved)
    <p style="color:#475569;font-size:14px;line-height:1.6;">
      Your leave balance has been updated accordingly. Please ensure your responsibilities are
      delegated before going on leave.
    </p>
    @else
    <p style="color:#475569;font-size:14px;line-height:1.6;">
      If you have questions about this decision, please speak with your {{ $actorRole === 'manager' ? 'reporting manager' : 'HR representative' }} directly.
      You may also submit a new application with revised dates if applicable.
    </p>
    @endif

    <div style="text-align:center;">
      <a href="{{ url('/my/leave') }}" class="btn">View My Leave</a>
    </div>
  </div>

  <div class="footer">
    &copy; {{ date('Y') }} {{ $company }} &bull;
    Automated notification from the Employee Portal. Do not reply.
  </div>
</div>
</body>
</html>
