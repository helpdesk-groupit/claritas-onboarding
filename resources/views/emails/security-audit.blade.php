<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: 'Segoe UI', Arial, sans-serif; background: #f1f5f9; margin: 0; padding: 0; }
        .wrapper { max-width: 680px; margin: 32px auto; background: #fff; border-radius: 10px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
        .header { background: linear-gradient(135deg, #dc2626, #991b1b); padding: 28px 32px; }
        .header h1 { color: #fff; margin: 0; font-size: 20px; }
        .header p { color: rgba(255,255,255,0.8); margin: 6px 0 0; font-size: 13px; }
        .body { padding: 28px 32px; }
        .summary-box { background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; padding: 16px 20px; margin-bottom: 24px; }
        .summary-box p { margin: 0; font-size: 14px; color: #7f1d1d; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th { background: #f8fafc; text-align: left; padding: 10px 12px; border-bottom: 2px solid #e2e8f0; color: #475569; font-weight: 600; text-transform: uppercase; font-size: 11px; letter-spacing: 0.04em; }
        td { padding: 10px 12px; border-bottom: 1px solid #f1f5f9; color: #1e293b; vertical-align: top; }
        tr:last-child td { border-bottom: none; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 20px; font-size: 11px; font-weight: 600; }
        .badge-red    { background: #fee2e2; color: #dc2626; }
        .badge-orange { background: #ffedd5; color: #ea580c; }
        .badge-yellow { background: #fef9c3; color: #ca8a04; }
        .badge-blue   { background: #dbeafe; color: #2563eb; }
        .footer { padding: 18px 32px; background: #f8fafc; border-top: 1px solid #e2e8f0; font-size: 12px; color: #94a3b8; }
    </style>
</head>
<body>
<div class="wrapper">
    <div class="header">
        <h1>⚠ Security Alert — Employee Portal</h1>
        <p>{{ $events->count() }} security event(s) detected &bull; {{ $periodLabel }}</p>
    </div>
    <div class="body">
        <div class="summary-box">
            <p>The automated security monitor has detected <strong>{{ $events->count() }} event(s)</strong> that require your attention. Please review the details below and take action where necessary.</p>
        </div>

        {{-- Summary counts --}}
        @php
            $byType = $events->groupBy('event_type');
        @endphp
        <p style="font-size:13px;font-weight:600;color:#1e293b;margin-bottom:12px;">Summary by event type:</p>
        <table style="margin-bottom:24px;">
            <thead><tr><th>Event Type</th><th>Count</th></tr></thead>
            <tbody>
            @foreach($byType as $type => $rows)
            <tr>
                <td>
                    @if($type === 'lockout')
                        <span class="badge badge-red">Account Lockout</span>
                    @elseif($type === 'failed_login')
                        <span class="badge badge-orange">Failed Login</span>
                    @elseif($type === 'unauthorized_access')
                        <span class="badge badge-yellow">Unauthorized Access</span>
                    @elseif($type === 'session_hijack')
                        <span class="badge badge-blue">Session Conflict</span>
                    @else
                        <span class="badge badge-blue">{{ ucwords(str_replace('_', ' ', $type)) }}</span>
                    @endif
                </td>
                <td><strong>{{ $rows->count() }}</strong></td>
            </tr>
            @endforeach
            </tbody>
        </table>

        {{-- Detail table --}}
        <p style="font-size:13px;font-weight:600;color:#1e293b;margin-bottom:12px;">Event log:</p>
        <table>
            <thead>
                <tr>
                    <th>Time</th>
                    <th>Event</th>
                    <th>User / Email</th>
                    <th>Role</th>
                    <th>IP Address</th>
                    <th>Details</th>
                </tr>
            </thead>
            <tbody>
            @foreach($events as $event)
            <tr>
                <td style="white-space:nowrap;">{{ $event->created_at->setTimezone('Asia/Kuala_Lumpur')->format('d M Y H:i') }}</td>
                <td>
                    @if($event->event_type === 'lockout')
                        <span class="badge badge-red">Lockout</span>
                    @elseif($event->event_type === 'failed_login')
                        <span class="badge badge-orange">Failed Login</span>
                    @elseif($event->event_type === 'unauthorized_access')
                        <span class="badge badge-yellow">Unauth. Access</span>
                    @elseif($event->event_type === 'session_hijack')
                        <span class="badge badge-blue">Session Conflict</span>
                    @else
                        <span class="badge badge-blue">{{ $event->event_type }}</span>
                    @endif
                </td>
                <td>{{ $event->work_email ?? '—' }}</td>
                <td>{{ $event->role ? ucwords(str_replace('_', ' ', $event->role)) : '—' }}</td>
                <td>{{ $event->ip_address ?? '—' }}</td>
                <td style="color:#64748b;">{{ $event->details ?? '—' }}</td>
            </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    <div class="footer">
        This is an automated security report from the Employee Portal system. Do not reply to this email.
        &bull; Generated at {{ now()->setTimezone('Asia/Kuala_Lumpur')->format('d M Y H:i') }} MYT
    </div>
</div>
</body>
</html>
