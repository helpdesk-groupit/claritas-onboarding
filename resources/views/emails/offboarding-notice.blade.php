<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Offboarding Notice</title>
    <style>
        body { font-family: Arial, sans-serif; background:#f4f6f9; margin:0; padding:0; }
        .wrapper { max-width:620px; margin:30px auto; background:#fff; border-radius:10px; overflow:hidden; box-shadow:0 2px 12px rgba(0,0,0,.08); }
        .header { background:linear-gradient(135deg,#7c3aed,#a855f7); color:#fff; padding:28px 30px; }
        .header h2 { margin:0 0 4px; font-size:20px; }
        .header p  { margin:0; font-size:13px; opacity:.85; }
        .body { padding:28px 30px; color:#334155; font-size:14px; line-height:1.7; }
        .steps { margin:16px 0; padding-left:0; list-style:none; }
        .steps li { margin-bottom:10px; padding-left:0; }
        .bullet-list { margin:12px 0 16px 0; padding-left:20px; }
        .bullet-list li { margin-bottom:8px; }
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
    $exitFormatted = $offboarding->exit_date
        ? $offboarding->exit_date->format('l, d M Y')
        : '—';
    $leavingName   = $offboarding->full_name ?? 'the employee';
    $company       = $offboarding->company ?? 'the company';
@endphp
<div class="wrapper">
    <div class="header">
        @if(!empty($__logoUrl))
        <div style="margin-bottom:12px;text-align:center;">
            <img src="{{ $__logoUrl }}" alt="Company Logo"
                 width="160" height="auto"
                 style="display:block;width:160px;height:auto;border:0;outline:none;text-decoration:none;margin:0 auto;">
        </div>
        @endif
        <h2>📋 Offboarding Notice</h2>
        <p>{{ $company }}</p>
    </div>
    <div class="body">

        @if($type === 'employee')
        <p>Dear <strong>{{ $leavingName }}</strong>,</p>
        <p>
            This is a gentle reminder that your last day is on <strong>{{ $exitFormatted }}</strong>.
        </p>
        @else
        {{-- Team / HR / IT notification --}}
        <p>Dear Team,</p>
        <p>
            This is a gentle reminder that <strong>{{ $leavingName }}</strong>'s last day is on
            <strong>{{ $exitFormatted }}</strong>.
        </p>
        @endif

        <p>On the last day procedure,</p>

        <ol class="steps" style="padding-left:20px;list-style:decimal;">
            <li>
                <strong>Reporting Manager – 9am – 1pm:</strong>
                Please ensure all the work on all projects on-hand are backed up and stored in the right place under shared files.
            </li>
            <li>
                <strong>IT Dept – 2pm – 5pm:</strong>
                Staff to return all technical assets and IT to revoke access of email and shared file access.
            </li>
            <li>
                <strong>HR Dept – 5pm:</strong>
                Handover of Door access and sign-off exit process.
            </li>
        </ol>

        <ul class="bullet-list">
            <li>
                <strong>Reporting Manager:</strong> Kindly revert to us after the 1st step is completed by 1pm to prompt the HRA Dept to process the return of asset, including laptop on behalf of Group IT Dept.
            </li>
            <li>
                <strong>Group IT:</strong> Kindly prepare the asset return form accordingly.
            </li>
        </ul>

        <p>Appreciate the cooperation.</p>

        <p style="margin-top:24px;font-size:13px;color:#64748b;">
            This is an automated notification from the Employee Portal. Please contact HR for any queries.
        </p>
    </div>
    <div class="footer">
        {{ $company }} &mdash; Confidential &mdash; Offboarding Notice
    </div>
</div>
</body>
</html>