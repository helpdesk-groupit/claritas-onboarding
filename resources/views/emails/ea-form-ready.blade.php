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
  .footer { background:#f8fafc; padding:20px 30px; text-align:center; font-size:12px; color:#94a3b8; border-top:1px solid #e2e8f0; }
</style>
</head>
<body>
@php
    $company = $employee->company ?? config('app.name');
    $__logoCompany = \App\Models\Company::where('name', $employee->company ?? '')->first();
    $__logoUrl = $__logoCompany?->logo_path ? asset('storage/' . $__logoCompany->logo_path) : null;
@endphp
<div class="email-wrap">

  <div class="header">
    @if($__logoUrl)
    <div style="margin-bottom:12px;"><img src="{{ $__logoUrl }}" alt="Logo" width="160" style="display:block;margin:0 auto;border:0;"></div>
    @endif
    <h1>Your EA Form is Ready</h1>
    <p>Borang EA (CP.8D) — Tax Year {{ $eaForm->year }}</p>
  </div>

  <div class="body">
    <div class="greeting">Dear {{ $employee->preferred_name ?? $employee->full_name }},</div>

    <p style="color:#475569;font-size:15px;line-height:1.6;">
      Your <strong>EA Form (Borang EA / CP.8D)</strong> for the tax year <strong>{{ $eaForm->year }}</strong>
      has been finalized and is now available for download.
    </p>

    <div class="info-box">
      <div class="detail-row">
        <span class="detail-label">Tax Year</span>
        <span class="detail-value">{{ $eaForm->year }}</span>
      </div>
      <div class="detail-row">
        <span class="detail-label">Total Remuneration</span>
        <span class="detail-value">RM {{ number_format($eaForm->total_remuneration, 2) }}</span>
      </div>
      <div class="detail-row">
        <span class="detail-label">Total Deductions</span>
        <span class="detail-value">RM {{ number_format($eaForm->total_deductions, 2) }}</span>
      </div>
      <div class="detail-row">
        <span class="detail-label">PCB / MTD Paid</span>
        <span class="detail-value">RM {{ number_format($eaForm->pcb_paid, 2) }}</span>
      </div>
    </div>

    <p style="color:#475569;font-size:14px;line-height:1.6;">
      You will need this form for your annual income tax filing (e-Filing) with LHDN.
      Please review the details and contact HR if any information is incorrect.
    </p>

    <div style="text-align:center;">
      <a href="{{ url('/my/ea-form?year=' . $eaForm->year) }}" class="btn">View My EA Form</a>
    </div>

    <p style="font-size:13px;color:#94a3b8;margin-top:20px;">
      <strong>Reminder:</strong> The income tax filing deadline is typically 30 April (non-business income)
      or 30 June (business income). Visit <a href="https://ez.hasil.gov.my" style="color:#2563eb;">ez.hasil.gov.my</a>
      to file your return.
    </p>

    <p style="font-size:13px;color:#94a3b8;">
      Do not reply to this email. Contact HR directly if you have questions.
    </p>
  </div>

  <div class="footer">
    © {{ date('Y') }} {{ $company }} &bull;
    Automated notification from the Employee Portal. Do not reply.
  </div>
</div>
</body>
</html>
