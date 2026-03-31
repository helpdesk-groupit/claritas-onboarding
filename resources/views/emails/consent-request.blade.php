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
  .consent-box { background:#f8fafc; border-left:4px solid #2563eb; border-radius:0 8px 8px 0; padding:16px 20px; margin:16px 0; font-size:14px; color:#334155; line-height:1.7; }
  .action-box { background:#eff6ff; border:1px solid #bfdbfe; border-radius:8px; padding:20px; margin:20px 0; text-align:center; }
  .btn { display:inline-block; background:linear-gradient(135deg,#1e3a5f,#2563eb); color:#fff; padding:13px 30px; border-radius:8px; text-decoration:none; font-weight:600; font-size:14px; margin-top:8px; }
  .footer { background:#f8fafc; padding:20px 30px; text-align:center; font-size:12px; color:#94a3b8; border-top:1px solid #e2e8f0; }
</style>
</head>
<body>
@php
$p = $onboarding->personalDetail;
$w = $onboarding->workDetail;
$preferredStr = $p?->preferred_name ? " ({$p->preferred_name})" : '';
$loginUrl = url('/login');
@endphp
@php
    $__logoUrl = null;
    $__logoCompany = \App\Models\Company::where('name', $w?->company ?? '')->first();
    if ($__logoCompany?->logo_path) {
        $__logoUrl = asset('storage/' . $__logoCompany->logo_path);
    }
@endphp
<div class="email-wrap">

  <div class="header">
    @if(!empty($__logoUrl))
    <div style="margin-bottom:12px;text-align:center;"><img src="{{ $__logoUrl }}" alt="Company Logo"
                         width="160" height="auto"
                         style="display:block;width:160px;height:auto;border:0;outline:none;text-decoration:none;margin:0 auto;"></div>
    @endif
    <h1>📋 Declaration &amp; Consent Required</h1>
    <p>Action required before your start date</p>
  </div>

  <div class="body">
    <div class="greeting">Dear {{ $p?->full_name ?? 'New Team Member' }}{{ $preferredStr }},</div>
    <p style="color:#475569;font-size:15px;line-height:1.6;">
      Your onboarding record with <strong>{{ $w?->company ?? 'the company' }}</strong> has been created. As part of the onboarding process, you are required to read and acknowledge the <strong>Declaration &amp; Consent</strong> before your start date.
    </p>

    <div class="consent-box">
      <p style="font-weight:600;margin:0 0 8px;">Personal Data Protection Act (PDPA) 2010 — Consent</p>
      <p style="margin:0 0 8px;">I hereby declare that all information provided is true and accurate to the best of my knowledge. I understand that any false or misleading information may result in disciplinary action or termination of employment.</p>
      <p style="margin:0 0 8px;">I consent to the collection, use, and disclosure of my personal data by the Company for purposes related to employment administration, payroll processing, statutory contributions and deductions (EPF, SOCSO, EIS, PCB), and employee benefits management, in compliance with the Personal Data Protection Act (PDPA) 2010 of Malaysia.</p>
      <p style="margin:0;">I also agree to promptly notify the HRA Department of any changes to the information provided, including updates to my contact details, banking information, or personal particulars.</p>
    </div>

    <div class="action-box">
      <p style="font-size:14px;color:#1e40af;margin:0 0 8px;font-weight:600;">
        ✅ Please log in to give your consent
      </p>
      <p style="font-size:13px;color:#475569;margin:0 0 14px;">
        Log in to the Employee Portal using your work email address. You will see a prompt to acknowledge the Declaration &amp; Consent on your profile page.
      </p>
      <!--[if mso]>
      <v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word"
        href="{{ $loginUrl }}"
        style="height:44px;v-text-anchor:middle;width:220px;" arcsize="10%"
        fillcolor="#1e3a5f" strokecolor="#1e3a5f">
        <w:anchorlock/>
        <center style="color:#ffffff;font-family:Arial,sans-serif;font-size:14px;font-weight:bold;">
          Log In &amp; Give Consent
        </center>
      </v:roundrect>
      <![endif]-->
      <!--[if !mso]><!-->
      <a href="{{ $loginUrl }}" class="btn">
        Log In &amp; Give Consent →
      </a>
      <!--<![endif]-->
      <p style="font-size:12px;color:#94a3b8;margin:12px 0 0;">
        Log in with: <strong>{{ $w?->company_email }}</strong>
      </p>
    </div>

    <p style="margin-top:20px;font-size:13px;color:#94a3b8;">
      If you have any questions, please contact the HR team. Do not reply to this email.
    </p>
  </div>

  <div class="footer">
    © {{ date('Y') }} {{ $w?->company ?? 'Employee Portal' }} &bull;
    This is an automated email from the Employee Portal. Please do not reply to this email.
  </div>
</div>
</body>
</html>