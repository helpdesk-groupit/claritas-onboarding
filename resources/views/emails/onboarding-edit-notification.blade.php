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
  .sections-list { list-style:none; padding:0; margin:8px 0 0; }
  .sections-list li { padding:3px 0; font-size:13px; }
  .sections-list li::before { content:"• "; color:#2563eb; font-weight:700; }
  .footer { background:#f8fafc; padding:20px 30px; text-align:center; font-size:12px; color:#94a3b8; border-top:1px solid #e2e8f0; }
</style>
</head>
<body>
@php
$p = $onboarding->personalDetail;
$w = $onboarding->workDetail;
$preferredStr = $p?->preferred_name ? " ({$p->preferred_name})" : '';
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
    <h1>Your Onboarding Information Has Been Updated</h1>
    <p>A notification from {{ $w?->company ?? 'HR' }}</p>
  </div>

  <div class="body">
    <div class="greeting">Dear {{ $p?->full_name ?? 'New Team Member' }}{{ $preferredStr }},</div>

    <p style="color:#475569;font-size:15px;line-height:1.6;">
      Your onboarding record at <strong>{{ $w?->company ?? 'the company' }}</strong> has been updated
      by <strong>{{ $editLog->edited_by_name ?? 'HR' }}</strong>
      ({{ str_replace('_', ' ', ucwords($editLog->edited_by_role ?? '')) }}).
    </p>

    @if(!empty($sections))
    <div class="info-box">
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

    <p style="color:#475569;font-size:14px;line-height:1.6;">
      Please log in to the employee portal to review your updated details.
      If you have any questions about the changes, please contact your HR team directly.
    </p>

    <p style="font-size:13px;color:#94a3b8;margin-top:16px;">
      Do not reply to this email. Contact HR directly if you have any concerns.
    </p>
  </div>

  <div class="footer">
    © {{ date('Y') }} {{ $w?->company ?? 'Employee Portal' }} &bull;
    Automated notification from the Employee Portal. Do not reply.
  </div>
</div>
</body>
</html>
