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
  .section-title { font-size:13px; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:#2563eb; margin:22px 0 8px; border-bottom:2px solid #e2e8f0; padding-bottom:4px; }
  .info-box { background:#f8fafc; border-left:4px solid #2563eb; border-radius:0 8px 8px 0; padding:16px 20px; margin:4px 0 16px; }
  .info-row { margin-bottom:6px; font-size:14px; }
  .info-label { color:#64748b; font-weight:600; }
  .asset-box { background:#f0fdf4; border-left:4px solid #22c55e; border-radius:0 8px 8px 0; padding:14px 20px; margin:4px 0 16px; font-size:14px; color:#1e293b; }
  .asset-item { display:inline-block; background:#dcfce7; color:#15803d; padding:3px 10px; border-radius:20px; font-size:12px; font-weight:600; margin:2px; }
  .btn { display:inline-block; background:linear-gradient(135deg,#1e3a5f,#2563eb); color:#fff; padding:13px 30px; border-radius:8px; text-decoration:none; font-weight:600; font-size:14px; margin-top:8px; }
  .aarf-box { background:#eff6ff; border:1px solid #bfdbfe; border-radius:8px; padding:20px; margin:20px 0; text-align:center; }
  .footer { background:#f8fafc; padding:20px 30px; text-align:center; font-size:12px; color:#94a3b8; border-top:1px solid #e2e8f0; }
</style>
</head>
<body>
@php
$p    = $onboarding->personalDetail;
$w    = $onboarding->workDetail;
$prov = $onboarding->assetProvisioning;
$aarf = $onboarding->aarf;

$preferredStr = $p?->preferred_name ? " ({$p->preferred_name})" : '';
$assets = [];
if ($prov) {
    if ($prov->laptop_provision)    $assets[] = 'Laptop';
    if ($prov->monitor_set)         $assets[] = 'Monitor Set';
    if ($prov->converter)           $assets[] = 'Converter';
    if ($prov->company_phone)       $assets[] = 'Company Phone';
    if ($prov->sim_card)            $assets[] = 'SIM Card';
    if ($prov->access_card_request) $assets[] = 'Access Card';
    if ($prov->office_keys)         $assets[] = 'Office Keys: ' . $prov->office_keys;
    if ($prov->others)              $assets[] = 'Others: ' . $prov->others;
}
@endphp
@php
    $__logoUrl = null;
    $__logoCompany = \App\Models\Company::where('name', $w?->company ?? '')->first();
    if ($__logoCompany?->logo_path) {
        $__logoUrl = asset('storage/' . $__logoCompany->logo_path);
    }
@endphp
<div class="email-wrap">

  {{-- Header --}}
  <div class="header">
    @if(!empty($__logoUrl))
    <div style="margin-bottom:12px;text-align:center;"><img src="{{ $__logoUrl }}" alt="Company Logo"
                         width="160" height="auto"
                         style="display:block;width:160px;height:auto;border:0;outline:none;text-decoration:none;margin:0 auto;"></div>
    @endif
    <h1>🎉 Welcome to {{ $w?->company ?? 'the company' }}!</h1>
    <p>We're excited to have you join the team</p>
  </div>

  <div class="body">
    <div class="greeting">Dear {{ $p?->full_name ?? 'New Team Member' }}{{ $preferredStr }},</div>
    <p style="color:#475569;font-size:15px;line-height:1.6;">
      We are delighted to welcome you to <strong>{{ $w?->company ?? 'our company' }}</strong>.
      Your onboarding has been completed.Below are your onboarding details for your reference.
    </p>

    {{-- Personal Info --}}
    <div class="section-title">Your Details</div>
    <div class="info-box">
      <table width="100%" cellpadding="5" cellspacing="0" border="0" style="font-size:14px;">
        <tr><td style="color:#64748b;font-weight:600;width:180px;">Full Name:</td><td style="color:#1e293b;">{{ $p?->full_name ?? '—' }}</td></tr>
        @if($p?->preferred_name)
        <tr><td style="color:#64748b;font-weight:600;">Preferred Name:</td><td style="color:#1e293b;">{{ $p->preferred_name }}</td></tr>
        @endif
        <tr><td style="color:#64748b;font-weight:600;">Company:</td><td style="color:#1e293b;">{{ $w?->company ?? '—' }}</td></tr>
        <tr><td style="color:#64748b;font-weight:600;">Department:</td><td style="color:#1e293b;">{{ $w?->department ?? '—' }}</td></tr>
        <tr><td style="color:#64748b;font-weight:600;">Designation:</td><td style="color:#1e293b;">{{ $w?->designation ?? '—' }}</td></tr>
        <tr><td style="color:#64748b;font-weight:600;">Reporting Manager:</td><td style="color:#1e293b;">{{ $w?->reporting_manager ?? '—' }}</td></tr>
        <tr><td style="color:#64748b;font-weight:600;">Manager Email:</td><td style="color:#1e293b;">{{ $w?->reporting_manager_email ?? '—' }}</td></tr>
        <tr><td style="color:#64748b;font-weight:600;">Start Date:</td><td style="color:#1e293b;"><strong>{{ $w?->start_date?->format('d M Y') ?? '—' }}</strong></td></tr>
        <tr><td style="color:#64748b;font-weight:600;">Office Location:</td><td style="color:#1e293b;">{{ $w?->office_location ?? '—' }}</td></tr>
        <tr><td style="color:#64748b;font-weight:600;">Work Email:</td><td style="color:#1e293b;">{{ $w?->company_email ?? '—' }}</td></tr>
        <tr><td style="color:#64748b;font-weight:600;">Google ID:</td><td style="color:#1e293b;">{{ $w?->google_id ?? '—' }}</td></tr>
      </table>
    </div>

    {{-- Assets --}}
    <div class="section-title">Asset Provisioning</div>
    <div class="asset-box">
      @if(count($assets) > 0)
        @foreach($assets as $asset)
          <span class="asset-item">✓ {{ $asset }}</span>
        @endforeach
      @else
        <span style="color:#64748b;">No assets provisioned for this onboarding.</span>
      @endif
    </div>

    {{-- AARF --}}
    @if($aarf && $aarf->acknowledgement_token)
    <div class="aarf-box">
      <p style="font-size:14px;color:#1e40af;margin:0 0 12px;font-weight:600;">
        📋 Action Required: Asset Acceptance &amp; Return Form (AARF)
      </p>
      <p style="font-size:13px;color:#475569;margin:0 0 14px;">
        Please review and acknowledge your AARF to confirm receipt of the above assets.
      </p>
      {{-- VML button for Outlook, regular anchor for all other clients --}}
      <!--[if mso]>
      <v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word"
        href="{{ url('/aarf/' . $aarf->acknowledgement_token) }}"
        style="height:44px;v-text-anchor:middle;width:260px;" arcsize="10%"
        fillcolor="#1e3a5f" strokecolor="#1e3a5f">
        <w:anchorlock/>
        <center style="color:#ffffff;font-family:Arial,sans-serif;font-size:14px;font-weight:bold;">
          View &amp; Acknowledge AARF
        </center>
      </v:roundrect>
      <![endif]-->
      <!--[if !mso]><!-->
      <a href="{{ url('/aarf/' . $aarf->acknowledgement_token) }}" class="btn">
        View &amp; Acknowledge AARF →
      </a>
      <!--<![endif]-->
    </div>
    @endif

    <p style="margin-top:20px;font-size:13px;color:#94a3b8;">
      If you have any questions before your start date, please contact the HR team.
    </p>
  </div>

  <div class="footer">
    © {{ date('Y') }} {{ $w?->company ?? 'Employee Portal' }} &bull;
    This is an automated email from the Employee Portal. Please do not reply to this email.
  </div>
</div>
</body>
</html>