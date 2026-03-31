<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
  body { font-family:'Segoe UI',Arial,sans-serif; background:#f1f5f9; margin:0; padding:20px; }
  .wrap { max-width:620px; margin:0 auto; background:#fff; border-radius:12px; overflow:hidden; box-shadow:0 2px 12px rgba(0,0,0,0.08); }
  .header { background:linear-gradient(135deg,#1e3a5f,#2563eb); padding:28px 30px; text-align:center; color:#fff; }
  .header h1 { margin:0; font-size:20px; font-weight:700; }
  .header p { margin:6px 0 0; opacity:.8; font-size:13px; }
  .body { padding:28px 30px; }
  .section-title { font-size:13px; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:#2563eb; margin:20px 0 8px; border-bottom:2px solid #e2e8f0; padding-bottom:4px; }
  .info-box { background:#f8fafc; border-left:4px solid #2563eb; border-radius:0 8px 8px 0; padding:14px 18px; margin:4px 0 16px; }
  .info-row { margin-bottom:6px; font-size:14px; }
  .info-label { color:#64748b; font-weight:600; }
  .asset-box { background:#f0fdf4; border-left:4px solid #22c55e; border-radius:0 8px 8px 0; padding:14px 18px; margin:4px 0 16px; font-size:14px; }
  .asset-item { display:inline-block; background:#dcfce7; color:#15803d; padding:3px 10px; border-radius:20px; font-size:12px; font-weight:600; margin:2px; }
  .footer { background:#f8fafc; padding:16px 30px; text-align:center; font-size:12px; color:#94a3b8; border-top:1px solid #e2e8f0; }
  .ics-note { background:#eff6ff; border:1px solid #bfdbfe; border-radius:8px; padding:14px 18px; margin-top:20px; font-size:13px; color:#1e40af; }
</style>
</head>
<body>
@php
$p    = $onboarding->personalDetail;
$w    = $onboarding->workDetail;
$prov = $onboarding->assetProvisioning;

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

<div class="wrap">

  {{-- Header --}}
  <div class="header">
    @if(!empty($__logoUrl))
    <div style="margin-bottom:12px;text-align:center;"><img src="{{ $__logoUrl }}" alt="Company Logo"
                         width="160" height="auto"
                         style="display:block;width:160px;height:auto;border:0;outline:none;text-decoration:none;margin:0 auto;"></div>
    @endif
    <h1>📅 New Hire Onboarding — Calendar Invite</h1>
    <p>{{ $recipientName }} &bull; Joining {{ $w?->start_date?->format('d M Y') }}</p>
  </div>

  <div class="body">
    <p style="color:#475569;font-size:14px;line-height:1.6;margin-top:0;">
      Dear {{ $recipientName }},<br><br>
      A new hire onboarding has been scheduled. Please review the details below and accept
      the calendar invite attached to this email.
    </p>

    {{-- Employee Details --}}
    <div class="section-title">New Hire Details</div>
    <div class="info-box">
      <table width="100%" cellpadding="5" cellspacing="0" border="0" style="font-size:14px;">
        <tr><td style="color:#64748b;font-weight:600;width:180px;">Full Name:</td><td style="color:#1e293b;">{{ $p?->full_name ?? '—' }}{{ $preferredStr }}</td></tr>
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
        <tr><td style="color:#64748b;font-weight:600;">Company Email:</td><td style="color:#1e293b;">{{ $w?->company_email ?? '—' }}</td></tr>
        <tr><td style="color:#64748b;font-weight:600;">Google ID:</td><td style="color:#1e293b;">{{ $w?->google_id ?? '—' }}</td></tr>
      </table>
    </div>

    {{-- Asset Provisioning --}}
    <div class="section-title">Asset Provisioning (Section C)</div>
    <div class="asset-box">
      @if(count($assets) > 0)
        @foreach($assets as $asset)
          <span class="asset-item">✓ {{ $asset }}</span>
        @endforeach
      @else
        <span style="color:#64748b;">No assets provisioned for this onboarding.</span>
      @endif
    </div>

    <div class="ics-note">
      📎 A <strong>.ics calendar file</strong> is attached to this email.
      Open it to add the onboarding start date to your calendar.
    </div>
  </div>

  <div class="footer">
    © {{ date('Y') }} {{ $w?->company ?? 'Claritas Asia Sdn. Bhd.' }} &bull;
    This is an automated notification from the Employee Portal.
  </div>
</div>
</body>
</html>