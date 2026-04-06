<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: 'Segoe UI', Arial, sans-serif; background: #f1f5f9; margin: 0; padding: 0; }
        .wrapper { max-width: 640px; margin: 32px auto; background: #fff; border-radius: 10px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
        .header-critical { background: linear-gradient(135deg, #dc2626, #7f1d1d); padding: 28px 32px; }
        .header-high     { background: linear-gradient(135deg, #ea580c, #9a3412); padding: 28px 32px; }
        .header-medium   { background: linear-gradient(135deg, #d97706, #92400e); padding: 28px 32px; }
        .header-low      { background: linear-gradient(135deg, #2563eb, #1e40af); padding: 28px 32px; }
        .header h1 { color: #fff; margin: 0; font-size: 20px; }
        .header p  { color: rgba(255,255,255,0.8); margin: 6px 0 0; font-size: 13px; }
        .body { padding: 28px 32px; }
        .alert-box { border-radius: 8px; padding: 16px 20px; margin-bottom: 20px; }
        .alert-critical { background: #fef2f2; border: 1px solid #fecaca; }
        .alert-high     { background: #fff7ed; border: 1px solid #fed7aa; }
        .alert-medium   { background: #fffbeb; border: 1px solid #fde68a; }
        .alert-low      { background: #eff6ff; border: 1px solid #bfdbfe; }
        .alert-box p { margin: 0; font-size: 14px; color: #1e293b; line-height: 1.5; }
        .detail-table { width: 100%; border-collapse: collapse; font-size: 13px; margin-bottom: 20px; }
        .detail-table td { padding: 8px 0; border-bottom: 1px solid #f1f5f9; color: #475569; }
        .detail-table td:first-child { font-weight: 600; color: #1e293b; width: 140px; }
        .severity-badge { display: inline-block; padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; }
        .severity-critical { background: #fecaca; color: #dc2626; }
        .severity-high     { background: #fed7aa; color: #ea580c; }
        .severity-medium   { background: #fde68a; color: #92400e; }
        .severity-low      { background: #bfdbfe; color: #2563eb; }
        .action-box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px 20px; margin-top: 16px; }
        .action-box h3 { margin: 0 0 8px; font-size: 14px; color: #1e293b; }
        .action-box ul { margin: 0; padding-left: 20px; font-size: 13px; color: #475569; line-height: 1.7; }
        .footer { padding: 18px 32px; background: #f8fafc; border-top: 1px solid #e2e8f0; font-size: 12px; color: #94a3b8; }
    </style>
</head>
<body>
@php
    $severity = $alert['severity'] ?? 'high';
@endphp
<div class="wrapper">
    <div class="header header-{{ $severity }}">
        <h1>🚨 Suspicious Activity Detected</h1>
        <p>Employee Portal — Immediate attention required</p>
    </div>
    <div class="body">
        <div class="alert-box alert-{{ $severity }}">
            <p><span class="severity-badge severity-{{ $severity }}">{{ strtoupper($severity) }}</span></p>
            <p style="margin-top: 10px; font-size: 16px; font-weight: 600;">{{ $alert['title'] }}</p>
            <p style="margin-top: 6px;">{{ $alert['description'] }}</p>
        </div>

        <table class="detail-table">
            @if(!empty($alert['timestamp']))
            <tr><td>Time</td><td>{{ $alert['timestamp'] }}</td></tr>
            @endif
            @if(!empty($alert['ip_address']))
            <tr><td>Source IP</td><td><code>{{ $alert['ip_address'] }}</code></td></tr>
            @endif
            @if(!empty($alert['work_email']))
            <tr><td>User</td><td>{{ $alert['work_email'] }}</td></tr>
            @endif
            @if(!empty($alert['role']))
            <tr><td>Role</td><td>{{ ucwords(str_replace('_', ' ', $alert['role'])) }}</td></tr>
            @endif
            @if(!empty($alert['url']))
            <tr><td>URL</td><td style="word-break:break-all;">{{ $alert['url'] }}</td></tr>
            @endif
        </table>

        <div class="action-box">
            <h3>Recommended Actions</h3>
            <ul>
                @if($severity === 'critical')
                <li>Immediately investigate — this may be an active attack</li>
                <li>Consider temporarily blocking the source IP</li>
                <li>Verify the affected user account has not been compromised</li>
                <li>Check if other accounts show similar patterns</li>
                @elseif($severity === 'high')
                <li>Review recent activity from this IP / user</li>
                <li>Verify the user's identity if behavior is unexpected</li>
                <li>Monitor for escalating activity over the next hour</li>
                @else
                <li>Review during the next security check</li>
                <li>Monitor for recurring patterns over the next 24 hours</li>
                @endif
                <li>Log your investigation findings in the security incident record</li>
            </ul>
        </div>
    </div>
    <div class="footer">
        <p style="margin: 0;">This is an automated security alert from the Employee Portal threat detection system.<br>
        Alerts are sent to IT Managers and Superadmins only. Do not forward this email externally.</p>
    </div>
</div>
</body>
</html>
