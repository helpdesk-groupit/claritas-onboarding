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
  .draft-table { width:100%; border-collapse:collapse; margin:8px 0 4px; font-size:14px; }
  .draft-table th { text-align:left; color:#64748b; font-size:12px; text-transform:uppercase; letter-spacing:.04em; padding:6px 8px; border-bottom:1px solid #e2e8f0; }
  .draft-table td { padding:8px; border-bottom:1px solid #f1f5f9; color:#1e293b; }
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
    <p>{{ $period }} &mdash; submission closes <strong>{{ $deadline }}</strong></p>
  </div>

  <div class="body">
    <div class="greeting">Dear {{ $employee->preferred_name ?? $employee->full_name }},</div>

    @if($type === 'none')
      <p style="color:#475569;font-size:15px;line-height:1.6;">
        We don't have any expense claim from you for <strong>{{ $period }}</strong> yet.
        If you have business expenses to claim (mileage, toll, meals, etc.), please file them
        before the deadline below.
      </p>
      <div class="info-box">
        <strong>Submission deadline:</strong> {{ $deadline }} (tomorrow)<br><br>
        Nothing to claim this month? You can simply ignore this email.
        Claims submitted after the deadline are processed in the next month's cycle.
      </div>
    @else
      <p style="color:#475569;font-size:15px;line-height:1.6;">
        You have <strong>{{ $drafts->count() }}</strong> unsubmitted draft claim{{ $drafts->count() == 1 ? '' : 's' }}.
        Please review and submit {{ $drafts->count() == 1 ? 'it' : 'them' }} for your reporting manager's approval before the deadline.
      </p>

      <table class="draft-table">
        <thead><tr><th>Event / claim</th><th>Items</th><th style="text-align:right;">Total</th></tr></thead>
        <tbody>
          @foreach($drafts as $d)
          <tr>
            <td>{{ $d->event ?: 'Untitled claim' }} <span style="color:#94a3b8;">({{ $d->claim_number }})</span></td>
            <td>{{ $d->item_count }}</td>
            <td style="text-align:right;">RM {{ number_format($d->total_with_gst, 2) }}</td>
          </tr>
          @endforeach
        </tbody>
      </table>

      <div class="info-box">
        <strong>Submission deadline:</strong> {{ $deadline }} (tomorrow)<br><br>
        Drafts not submitted by the deadline stay as drafts and roll into next month's cycle &mdash;
        they are <strong>not</strong> auto-submitted, so please submit them yourself.
      </div>
    @endif

    <p style="text-align:center;">
      <a href="{{ route('login') }}" class="btn">Open My Claims →</a>
    </p>
  </div>

  <div class="footer">
    This is an automated message from {{ $company }}.<br>
    Please do not reply directly to this email.
  </div>
</div>
</body>
</html>
