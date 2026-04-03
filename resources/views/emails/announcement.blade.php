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
  .announcement-title { font-size:20px; font-weight:700; color:#1e293b; margin-bottom:12px; }
  .announcement-body { font-size:15px; color:#475569; line-height:1.7; white-space:pre-line; }
  .attachments-box { background:#eff6ff; border-left:4px solid #2563eb; border-radius:0 8px 8px 0; padding:14px 18px; margin:20px 0; }
  .attachments-box p { margin:0 0 8px; font-size:13px; font-weight:600; color:#1e40af; }
  .attachment-link { display:inline-block; margin:3px 6px 3px 0; font-size:13px; color:#2563eb; text-decoration:none; }
  .footer { background:#f8fafc; padding:20px 30px; text-align:center; font-size:12px; color:#94a3b8; border-top:1px solid #e2e8f0; }
</style>
</head>
<body>
@php
    $announcementCompanies = $announcement->companies ?? [];
    $company = !empty($announcementCompanies) ? implode(', ', $announcementCompanies) : 'All Companies';
    $logoCompany = \App\Models\Company::where('name', $announcementCompanies[0] ?? '')->first();
    $logoUrl = $logoCompany?->logo_path ? asset('storage/' . $logoCompany->logo_path) : null;
    $displayName = $employee->preferred_name ?: $employee->full_name;
@endphp
<div class="email-wrap">

  <div class="header">
    @if($logoUrl)
    <div style="margin-bottom:12px;"><img src="{{ $logoUrl }}" alt="Logo" width="160" style="display:block;margin:0 auto;border:0;"></div>
    @endif
    <h1>📢 New Announcement</h1>
    <p>From {{ $company }}</p>
  </div>

  <div class="body">
    <div class="greeting">Dear {{ $displayName }},</div>

    <p style="color:#475569;font-size:14px;line-height:1.6;margin-bottom:20px;">
      There is a new announcement from
      <strong>{{ $announcement->creator?->employee?->full_name ?? $announcement->creator?->name ?? 'HR' }}</strong>.
    </p>

    <div class="announcement-title">{{ $announcement->title }}</div>
    <div class="announcement-body">{{ $announcement->body }}</div>

    @if(!empty($announcement->attachment_paths))
    <div class="attachments-box">
      <p><i style="margin-right:4px;">📎</i>Attachments ({{ count($announcement->attachment_paths) }})</p>
      @foreach($announcement->attachment_paths as $i => $path)
      <a href="{{ asset('storage/'.$path) }}" class="attachment-link" target="_blank">
        File {{ $i + 1 }}
      </a>
      @endforeach
      <p style="margin:8px 0 0;font-size:12px;color:#64748b;">Log in to the Employee Portal to view full-quality attachments.</p>
    </div>
    @endif

    <p style="font-size:13px;color:#94a3b8;margin-top:24px;">
      Do not reply to this email. Contact your HR team directly if you have any questions.
    </p>
  </div>

  <div class="footer">
    © {{ date('Y') }} {{ $company }} &bull;
    Automated notification from the Employee Portal. Do not reply.
  </div>
</div>
</body>
</html>
