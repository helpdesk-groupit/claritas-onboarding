<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Farewell & Best Wishes</title>
    <style>
        body { font-family: Arial, sans-serif; background:#f4f6f9; margin:0; padding:0; }
        .wrapper { max-width:600px; margin:30px auto; background:#fff; border-radius:10px; overflow:hidden; box-shadow:0 2px 12px rgba(0,0,0,.08); }
        .header { background:linear-gradient(135deg,#0f766e,#0d9488); color:#fff; padding:28px 30px; }
        .header h2 { margin:0 0 4px; font-size:20px; }
        .header p  { margin:0; font-size:13px; opacity:.85; }
        .body { padding:28px 30px; color:#334155; }
        .info-box { background:#f1f5f9; border-radius:8px; padding:16px 20px; margin:20px 0; }
        .info-box table { width:100%; border-collapse:collapse; }
        .info-box td { padding:5px 0; font-size:14px; }
        .info-box td:first-child { color:#64748b; width:160px; font-weight:600; }
        .farewell-box { background:#f0fdf4; border:1px solid #86efac; border-radius:8px; padding:18px 20px; margin:20px 0; font-size:14px; color:#166534; }
        .notice { background:#fffbeb; border:1px solid #fcd34d; border-radius:8px; padding:14px 18px; font-size:13px; color:#92400e; margin-top:16px; }
        .aarf-box { background:#eff6ff; border:1px solid #bfdbfe; border-radius:8px; padding:18px 20px; margin:20px 0; text-align:center; }
        .btn { display:inline-block; background:linear-gradient(135deg,#1e3a5f,#2563eb); color:#fff; padding:12px 28px; border-radius:8px; text-decoration:none; font-weight:600; font-size:14px; margin-top:8px; }
        .footer { background:#f8fafc; padding:16px 30px; font-size:12px; color:#94a3b8; text-align:center; border-top:1px solid #e2e8f0; }
    </style>
</head>
<body>
@php
    $__logoUrl = null;
    $__logoCompany = \App\Models\Company::where('name', $offboarding->company ?? '')->first();
    if ($__logoCompany?->logo_path) {
        $__logoUrl = asset('storage/' . $__logoCompany->logo_path);
    }
@endphp
@php
    $company  = $offboarding->company ?? 'the company';
    $employee = $offboarding->employee;

    // Resolve AARF for the link
    $aarf = null;
    if ($employee) {
        $aarf = $employee->resolveAarf();
    }
    if (!$aarf && $offboarding->onboarding_id) {
        $aarf = \App\Models\Aarf::where('onboarding_id', $offboarding->onboarding_id)->first();
    }
@endphp
<div class="wrapper">
    <div class="header">
        @if(!empty($__logoUrl))
        <div style="margin-bottom:12px;text-align:center;"><img src="{{ $__logoUrl }}" alt="Company Logo"
                         width="160" height="auto"
                         style="display:block;width:160px;height:auto;border:0;outline:none;text-decoration:none;margin:0 auto;"></div>
        @endif
        <h2>🎉 Farewell & Best Wishes</h2>
        <p>Today marks your last day at {{ $company }}.</p>
    </div>
    <div class="body">
        <p>Dear <strong>{{ $offboarding->full_name }}</strong>,</p>
        <p>Today is your last day with us, and we want to take a moment to thank you for your contributions and dedication during your time at {{ $company }}.</p>

        <div class="farewell-box">
            <strong>🌟 Thank You</strong><br><br>
            It has been a pleasure working with you. Your hard work, commitment, and the impact you have made during your time with us will be remembered. We wish you all the very best in your future endeavours.
        </div>

        <div class="info-box">
            <table>
                <tr><td>Employee</td><td><strong>{{ $offboarding->full_name }}</strong></td></tr>
                <tr><td>Designation</td><td>{{ $offboarding->designation ?? '—' }}</td></tr>
                <tr><td>Department</td><td>{{ $offboarding->department ?? '—' }}</td></tr>
                <tr><td>Last Working Day</td><td><strong>{{ $offboarding->exit_date?->format('d M Y') ?? '—' }}</strong></td></tr>
            </table>
        </div>

        @if($aarf && $aarf->acknowledgement_token)
        <div class="aarf-box">
            <p style="font-size:14px;color:#1e40af;margin:0 0 8px;font-weight:600;">
                📋 Your Asset Acceptance &amp; Return Form (AARF)
            </p>
            <p style="font-size:13px;color:#475569;margin:0 0 12px;">
                This is for your records. All assets should have been returned to the IT department.
                No acknowledgement action is required.
            </p>
            <!--[if mso]>
            <v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word"
              href="{{ url('/aarf/' . $aarf->acknowledgement_token) }}"
              style="height:44px;v-text-anchor:middle;width:180px;" arcsize="10%"
              fillcolor="#1e3a5f" strokecolor="#1e3a5f">
              <w:anchorlock/>
              <center style="color:#ffffff;font-family:Arial,sans-serif;font-size:14px;font-weight:bold;">View AARF</center>
            </v:roundrect>
            <![endif]-->
            <!--[if !mso]><!-->
            <a href="{{ url('/aarf/' . $aarf->acknowledgement_token) }}" class="btn">
                View AARF
            </a>
            <!--<![endif]-->
        </div>
        @endif

        <div class="notice">
            <strong>📋 Final Reminders:</strong>
            <ul style="margin:8px 0 0 0;padding-left:18px;">
                <li>Please ensure all company assets have been returned to the IT department</li>
                <li>Your company email and system accesses will be deactivated at end of day</li>
                <li>Please retain a copy of your payslips and any personal documents</li>
                <li>HR will be in touch regarding your final clearance and any outstanding matters</li>
            </ul>
        </div>

        <p style="margin-top:24px;font-size:14px;color:#334155;">
            Once again, thank you for everything. Stay in touch, and we wish you great success ahead! 🚀
        </p>

        <p style="font-size:13px;color:#64748b;">
            Warm regards,<br>
            <strong>HR Team</strong><br>
            {{ $company }}
        </p>
    </div>
    <div class="footer">
        {{ $company }} &mdash; Confidential &mdash; Final Day Notification
    </div>
</div>
</body>
</html>