<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Offboarding Reminder — 1 Week</title>
    <style>
        body { font-family: Arial, sans-serif; background:#f4f6f9; margin:0; padding:20px; }
        .wrapper { max-width:620px; margin:0 auto; background:#fff; border-radius:10px; overflow:hidden; box-shadow:0 2px 12px rgba(0,0,0,.08); }
        .header { background:linear-gradient(135deg,#d97706,#f59e0b); color:#fff; padding:28px 30px; }
        .header h2 { margin:0 0 4px; font-size:20px; }
        .header p  { margin:0; font-size:13px; opacity:.85; }
        .body { padding:28px 30px; color:#334155; font-size:15px; line-height:1.8; }
        .step { margin-bottom:8px; }
        .bullets { margin:16px 0 0 0; padding:0; list-style:none; }
        .bullets li { padding:6px 0 6px 18px; position:relative; font-size:14px; color:#334155; }
        .bullets li:before { content:"•"; position:absolute; left:0; color:#d97706; font-weight:bold; }
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
    $empName       = $offboarding->full_name ?? 'the employee';
    $exitDate      = $offboarding->exit_date;
    $exitDayName   = $exitDate ? $exitDate->format('l') : 'the exit day';
    $exitFormatted = $exitDate ? $exitDate->format('d M Y') : '(date to be confirmed)';
    $company       = $offboarding->company ?? 'the company';
    $reportingMgr  = $offboarding->employee?->reporting_manager ?? 'Reporting Manager';
    $mgrFirstName  = explode(' ', trim($reportingMgr))[0];
@endphp
<div class="wrapper">
    <div class="header">
        @if(!empty($__logoUrl))
        <div style="margin-bottom:12px;text-align:center;"><img src="{{ $__logoUrl }}" alt="Company Logo"
                         width="160" height="auto"
                         style="display:block;width:160px;height:auto;border:0;outline:none;text-decoration:none;margin:0 auto;"></div>
        @endif
        <h2>⏰ Offboarding Reminder — 1 Week Left</h2>
        <p>{{ $company }}</p>
    </div>
    <div class="body">

        <p>Dear <strong>{{ $mgrFirstName }}</strong>,</p>
        <p>This is a gentle reminder that <strong>{{ $empName }}</strong>'s last day is on
           <strong>{{ $exitDayName }}, {{ $exitFormatted }}</strong> — that is <strong>1 week from now</strong>.</p>

        <p>On the last day procedure,</p>
        <div class="step">1. <strong>Reporting Manager — 9am to 1pm:</strong> Please ensure all the work on all projects on-hand are backed up and stored in the right place under shared files.</div>
        <div class="step">2. <strong>IT Department — 2pm to 5pm:</strong> Staff to return all technical assets and IT to revoke access of email and shared file access.</div>
        <div class="step">3. <strong>HR Department — 5pm:</strong> Handover of door access and sign-off exit process.</div>

        <ul class="bullets">
            <li><strong>Reporting Manager:</strong> Kindly revert to us after the 1st step is completed by 1pm to prompt the HR Department to process the return of asset, including laptop on behalf of Group IT Department.</li>
            <li><strong>Group IT:</strong> Kindly prepare the asset return form accordingly.</li>
        </ul>

        <p style="margin-top:20px;">Appreciate the cooperation.</p>

        <p style="margin-top:24px;font-size:13px;color:#64748b;">
            This is an automated notification from the Employee Portal. Please contact HR for any queries.
        </p>
    </div>
    <div class="footer">
        {{ $company }} &mdash; Confidential &mdash; Offboarding Reminder
    </div>
</div>
</body>
</html>