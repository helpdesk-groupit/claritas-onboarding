<!DOCTYPE html>
<html>
<head><meta charset="UTF-8">
<style>
  body { font-family:'Segoe UI',Arial,sans-serif; background:#f1f5f9; margin:0; padding:20px; }
  .email-wrap { max-width:620px; margin:0 auto; background:#fff; border-radius:12px; overflow:hidden; box-shadow:0 2px 12px rgba(0,0,0,0.08); }
  .header { padding:30px; text-align:center; }
  .header h1 { color:#fff; margin:0; font-size:21px; font-weight:700; }
  .header p { color:rgba(255,255,255,0.85); margin:6px 0 0; font-size:14px; }
  .body { padding:30px; }
  .box { border-radius:0 8px 8px 0; padding:14px 18px; margin:14px 0; font-size:14px; }
  table { width:100%; border-collapse:collapse; margin:8px 0; font-size:14px; }
  th { text-align:left; color:#64748b; font-size:12px; text-transform:uppercase; letter-spacing:.04em; padding:6px 8px; border-bottom:1px solid #e2e8f0; }
  td { padding:8px; border-bottom:1px solid #f1f5f9; color:#1e293b; }
  .btn { display:inline-block; background:linear-gradient(135deg,#2563eb,#1d4ed8); color:#fff; padding:12px 28px; border-radius:8px; text-decoration:none; font-weight:600; font-size:14px; margin-top:14px; }
  .footer { background:#f8fafc; padding:18px 30px; text-align:center; font-size:12px; color:#94a3b8; border-top:1px solid #e2e8f0; }
</style></head>
<body>
<div class="email-wrap">
  <div class="header" style="background:{{ $lastCall ? 'linear-gradient(135deg,#7f1d1d,#dc2626)' : 'linear-gradient(135deg,#1e3a5f,#2563eb)' }};">
    <h1>{{ $lastCall ? 'HR cutoff is TODAY' : 'Claims awaiting your approval' }}</h1>
    <p>{{ $claims->count() }} claim(s) &mdash; approve by <strong>{{ $cutoff }}</strong></p>
  </div>
  <div class="body">
    <p style="color:#475569;font-size:15px;line-height:1.6;">Dear {{ $manager->preferred_name ?? $manager->full_name }},</p>
    <p style="color:#475569;font-size:15px;line-height:1.6;">
      You have <strong>{{ $claims->count() }}</strong> claim(s) routed to you that are still awaiting approval.
      Please approve {{ $claims->count() == 1 ? 'it' : 'them' }} by the <strong>{{ $cutoff }}</strong> HR cutoff so
      {{ $claims->count() == 1 ? 'it' : 'they' }} can be processed this cycle &mdash; anything approved after the
      cutoff rolls into next month's cycle.
    </p>
    <table>
      <thead><tr><th>Employee</th><th>Event / claim</th><th style="text-align:right;">Total</th></tr></thead>
      <tbody>
        @foreach($claims as $c)
        <tr>
          <td>{{ $c->employee->full_name ?? '—' }}</td>
          <td>{{ $c->event ?: 'Untitled' }} <span style="color:#94a3b8;">({{ $c->claim_number }})</span></td>
          <td style="text-align:right;">RM {{ number_format($c->total_with_gst, 2) }}</td>
        </tr>
        @endforeach
      </tbody>
    </table>
    @include('emails.partials._button', ['url' => route('login'), 'label' => 'Review & Approve →'])
  </div>
  <div class="footer">Automated message. Claims are not auto-approved &mdash; please review and approve them yourself.</div>
</div>
</body>
</html>
