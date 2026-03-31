<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Action Required: Declaration &amp; Consent</title>
</head>
<body style="margin:0;padding:0;background:#f1f5f9;font-family:'Segoe UI',Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:32px 0;">
<tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.08);">

    {{-- Company Logo --}}
    @php
        $company = \App\Models\Company::where('name', $employee->company)->first();
    @endphp
    @if($company && $company->logo_path)
    <tr>
        <td align="center" style="padding:20px 32px 0;">
            <img src="{{ asset('storage/' . $company->logo_path) }}" width="160" alt="{{ $employee->company }}" style="display:block;margin:0 auto;">
        </td>
    </tr>
    @endif

    {{-- Header --}}
    <tr>
        <td style="background:linear-gradient(135deg,#1e3a5f,#2563eb);padding:28px 32px;">
            <h2 style="margin:0;color:#fff;font-size:20px;font-weight:700;">Action Required</h2>
            <p style="margin:6px 0 0;color:#bfdbfe;font-size:13px;">Re-acknowledge Declaration &amp; Consent</p>
        </td>
    </tr>

    {{-- Body --}}
    <tr>
        <td style="padding:28px 32px;">
            <p style="margin:0 0 16px;color:#1e293b;font-size:15px;">
                Dear <strong>{{ $employee->full_name ?? 'Employee' }}</strong>,
            </p>
            <p style="margin:0 0 16px;color:#475569;font-size:14px;line-height:1.7;">
                Your employee record has been updated by HR on
                <strong>{{ $editLog->created_at->format('d M Y, h:i A') }}</strong>.
                As required under our PDPA policy, you must log in to the Employee Portal and re-acknowledge the Declaration &amp; Consent on your profile page.
            </p>

            @if(!empty($editLog->sections_changed))
            <div style="background:#f8fafc;border:1px solid #e2e8f0;border-left:4px solid #f59e0b;border-radius:6px;padding:14px 16px;margin-bottom:16px;">
                <p style="margin:0 0 8px;font-weight:600;font-size:13px;color:#92400e;">Sections Updated:</p>
                <ul style="margin:0;padding-left:20px;color:#475569;font-size:13px;">
                    @foreach($editLog->sections_changed as $section)
                    <li style="margin-bottom:4px;">{{ $section }}</li>
                    @endforeach
                </ul>
                @if($editLog->change_notes)
                <p style="margin:10px 0 0;font-size:13px;color:#64748b;"><strong>Note from HR:</strong> {{ $editLog->change_notes }}</p>
                @endif
            </div>
            @endif

            {{-- Steps --}}
            <div style="background:#f0f9ff;border:1px solid #bae6fd;border-radius:6px;padding:14px 16px;margin-bottom:20px;">
                <p style="margin:0 0 10px;font-weight:600;font-size:13px;color:#0c4a6e;">Steps to acknowledge:</p>
                <table cellpadding="0" cellspacing="0" style="font-size:13px;color:#0369a1;line-height:1.7;">
                    <tr><td style="padding-right:8px;vertical-align:top;">1.</td><td>Click the button below to go to the Employee Portal login page.</td></tr>
                    <tr><td style="padding-right:8px;vertical-align:top;">2.</td><td>Log in using your work email and password. If you do not have an account yet, click <strong>"Register"</strong> to create one.</td></tr>
                    <tr><td style="padding-right:8px;vertical-align:top;">3.</td><td>You will be directed to your Profile page — scroll to the <strong>"Declaration &amp; Consent"</strong> section and click <strong>"I Acknowledge"</strong>.</td></tr>
                </table>
            </div>

            {{-- CTA Button --}}
            <div style="text-align:center;margin:24px 0;">
                <a href="{{ route('login') }}?redirect=profile-consent"
                   style="display:inline-block;background:#2563eb;color:#fff;text-decoration:none;font-weight:600;font-size:15px;padding:13px 32px;border-radius:8px;">
                    Log In to Acknowledge
                </a>
            </div>

            <p style="margin:0 0 4px;color:#94a3b8;font-size:12px;text-align:center;">
                Log in with your work email:
                <strong>{{ $employee->company_email ?? $employee->personal_email ?? '—' }}</strong>
            </p>
            @if($employee->personal_email && $employee->company_email && $employee->personal_email !== $employee->company_email)
            <p style="margin:0 0 4px;color:#94a3b8;font-size:12px;text-align:center;">
                (This notification was also sent to <strong>{{ $employee->personal_email }}</strong>)
            </p>
            @endif
            @if($editLog->consent_token_expires_at)
            <p style="margin:8px 0 0;color:#94a3b8;font-size:12px;text-align:center;">
                Please acknowledge before <strong>{{ $editLog->consent_token_expires_at->format('d M Y, h:i A') }}</strong>.
            </p>
            @endif
        </td>
    </tr>

    {{-- Footer --}}
    <tr>
        <td style="background:#f8fafc;padding:16px 32px;border-top:1px solid #e2e8f0;">
            <p style="margin:0;color:#94a3b8;font-size:11px;text-align:center;">
                This is an automated email from Claritas Onboarding System. Please do not reply to this email.
            </p>
        </td>
    </tr>

</table>
</td></tr>
</table>
</body>
</html>