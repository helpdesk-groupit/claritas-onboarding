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
  .alert-box { background:#fff7ed; border-left:4px solid #f59e0b; border-radius:0 8px 8px 0; padding:16px 20px; margin:16px 0; font-size:14px; color:#92400e; }
  .sections-list { list-style:none; padding:0; margin:8px 0 0; }
  .sections-list li { padding:3px 0; font-size:13px; }
  .sections-list li::before { content:"• "; color:#f59e0b; font-weight:700; }
  .consent-box { background:#f8fafc; border-left:4px solid #2563eb; border-radius:0 8px 8px 0; padding:16px 20px; margin:16px 0; font-size:13.5px; color:#334155; line-height:1.7; }
  .action-box { background:#eff6ff; border:1px solid #bfdbfe; border-radius:8px; padding:20px; margin:20px 0; text-align:center; }
  .btn { display:inline-block; background:#F5A623; color:#000; padding:13px 30px; border-radius:8px; text-decoration:none; font-weight:600; font-size:14px; margin-top:8px; }
  .footer { background:#f8fafc; padding:20px 30px; text-align:center; font-size:12px; color:#94a3b8; border-top:1px solid #e2e8f0; }
</style>
</head>
<body>
@php
$p = $onboarding->personalDetail;
$w = $onboarding->workDetail;
$preferredStr = $p?->preferred_name ? " ({$p->preferred_name})" : '';
$consentUrl = route('onboarding.re-consent.show', ['onboarding' => $onboarding->id, 'token' => $editLog->consent_token]);
$sections = $editLog->sections_changed ?? [];
@endphp
@php
    $__logoCompany = \App\Models\Company::where('name', $w?->company ?? '')->first();
    $__logoUrl = $__logoCompany?->logo_path ? asset('storage/' . $__logoCompany->logo_path) : null;
@endphp
<div class="email-wrap">

  <div class="header">
    @if($__logoUrl)
    <div style="margin-bottom:12px;"><img src="{{ $__logoUrl }}" alt="Logo" width="160" style="display:block;margin:0 auto;border:0;"></div>
    @endif
    <h1>Declaration &amp; Consent — Re-acknowledgement Required</h1>
    <p>Your onboarding information has been updated</p>
  </div>

  <div class="body">
    <div class="greeting">Dear {{ $p?->full_name ?? 'New Team Member' }}{{ $preferredStr }},</div>

    <p style="color:#475569;font-size:15px;line-height:1.6;">
      Your onboarding record at <strong>{{ $w?->company ?? 'the company' }}</strong> has been updated by HR.
      Because personal information was changed, you are required to <strong>re-acknowledge the Declaration &amp; Consent</strong>.
    </p>

    @if(!empty($sections))
    <div class="alert-box">
      <strong>Sections updated:</strong>
      <ul class="sections-list">
        @foreach($sections as $section)
        <li>{{ $section }}</li>
        @endforeach
      </ul>
      @if($editLog->change_notes)
      <p style="margin:8px 0 0;font-size:13px;"><strong>Note from HR:</strong> {{ $editLog->change_notes }}</p>
      @endif
    </div>
    @endif

    <div class="consent-box">
      <p style="font-weight:600;margin:0 0 8px;">Personal Data Protection Act (PDPA) 2010 — Consent</p>
      <p style="margin:0 0 8px;">I hereby declare that all information provided is true and accurate to the best of my knowledge. I understand that any false or misleading information may result in disciplinary action or termination of employment.</p>
      <p style="margin:0 0 8px;">I consent to the collection, use, and disclosure of my personal data by the Company for purposes related to employment administration, payroll processing, statutory contributions and deductions (EPF, SOCSO, EIS, PCB), and employee benefits management, in compliance with the Personal Data Protection Act (PDPA) 2010 of Malaysia.</p>
      <p style="margin:0;">I also agree to promptly notify the HRA Department of any changes to the information provided, including updates to my contact details, banking information, or personal particulars.</p>
    </div>

    <div class="action-box">
      <p style="font-size:14px;color:#1e40af;margin:0 0 8px;font-weight:600;">
        Please log in and re-acknowledge your consent
      </p>
      <p style="font-size:13px;color:#475569;margin:0 0 14px;">
        Click the button below. You will be asked to log in with your work credentials, then brought directly to the acknowledgement page.
        @if($editLog->consent_token_expires_at)
        This link expires on <strong>{{ $editLog->consent_token_expires_at->format('d M Y, h:i A') }}</strong>.
        @endif
      </p>
      <a href="{{ $consentUrl }}" class="btn">Log In &amp; Acknowledge →</a>
      <p style="font-size:12px;color:#94a3b8;margin:12px 0 0;">
        Log in with: <strong>{{ $w?->company_email }}</strong>
      </p>
    </div>

    <p style="font-size:13px;color:#94a3b8;margin-top:16px;">
      If you did not expect this email or have questions, please contact your HR team directly. Do not reply to this email.
    </p>
  </div>

  <div class="footer">
    © {{ date('Y') }} {{ $w?->company ?? 'Employee Portal' }} &bull;
    Automated notification from the Employee Portal. Do not reply.
  </div>
</div>
</body>
</html>