<!DOCTYPE html>
<html>
<head><meta charset="UTF-8">
<style>
  body { font-family:'Segoe UI',Arial,sans-serif; background:#f1f5f9; margin:0; padding:20px; }
  .email-wrap { max-width:620px; margin:0 auto; background:#fff; border-radius:12px; overflow:hidden; box-shadow:0 2px 12px rgba(0,0,0,0.08); }
  .header { background:linear-gradient(135deg,#7f1d1d,#dc2626); padding:30px; text-align:center; }
  .header h1 { color:#fff; margin:0; font-size:21px; font-weight:700; }
  .header p { color:rgba(255,255,255,0.85); margin:6px 0 0; font-size:14px; }
  .body { padding:30px; }
  .box { background:#fef2f2; border-left:4px solid #dc2626; border-radius:0 8px 8px 0; padding:14px 18px; margin:14px 0; font-size:14px; color:#991b1b; }
  .btn { display:inline-block; background:linear-gradient(135deg,#2563eb,#1d4ed8); color:#fff; padding:12px 28px; border-radius:8px; text-decoration:none; font-weight:600; font-size:14px; margin-top:14px; }
  .footer { background:#f8fafc; padding:18px 30px; text-align:center; font-size:12px; color:#94a3b8; border-top:1px solid #e2e8f0; }
</style></head>
<body>
@php $period = \Carbon\Carbon::create($claim->year, $claim->month)->format('F Y'); @endphp
<div class="email-wrap">
  <div class="header">
    <h1>HR rejected a claim you approved</h1>
    <p>{{ $claim->claim_number }} &mdash; {{ $claim->event ?: $period }}</p>
  </div>
  <div class="body">
    <p style="color:#475569;font-size:15px;line-height:1.6;">Dear {{ $manager->preferred_name ?? $manager->full_name }},</p>
    <p style="color:#475569;font-size:15px;line-height:1.6;">
      HR has rejected <strong>{{ $claim->employee->full_name }}</strong>'s claim
      <strong>{{ $claim->claim_number }}</strong> ({{ $claim->event ?: $period }}, RM {{ number_format($claim->total_with_gst, 2) }}),
      which you had approved. Please review HR's reason and <strong>release it to the employee</strong>
      so they can make a correction. You may add your own comment when releasing.
    </p>
    <div class="box">
      <strong>HR's reason:</strong><br>{{ $claim->hr_remarks }}
    </div>
    @include('emails.partials._button', ['url' => route('login'), 'label' => 'Review & Release →'])
  </div>
  <div class="footer">Automated message. The employee can't correct this claim until you release it.</div>
</div>
</body>
</html>
