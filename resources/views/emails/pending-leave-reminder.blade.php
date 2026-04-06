<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#f1f5f9;font-family:'Segoe UI',Helvetica,Arial,sans-serif;">
<div style="max-width:600px;margin:30px auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,0.08);">

    {{-- Header --}}
    <div style="background:linear-gradient(135deg,#f59e0b,#d97706);padding:28px 32px;text-align:center;">
        <h1 style="margin:0;color:#fff;font-size:22px;font-weight:700;">
            ⏰ Pending Leave Requests
        </h1>
        <p style="margin:8px 0 0;color:rgba(255,255,255,0.9);font-size:14px;">
            {{ $pendingApplications->count() }} request(s) awaiting your approval
        </p>
    </div>

    {{-- Body --}}
    <div style="padding:28px 32px;">
        <p style="color:#334155;font-size:15px;line-height:1.6;margin:0 0 20px;">
            Hi <strong>{{ $manager->preferred_name ?? $manager->full_name }}</strong>,
        </p>
        <p style="color:#334155;font-size:15px;line-height:1.6;margin:0 0 20px;">
            This is a reminder that you have <strong>{{ $pendingApplications->count() }}</strong> pending leave
            request(s) from your team members that require your action. Under the
            <strong>Employment Act 1955 (Malaysia)</strong>, employees are entitled to timely responses
            on their leave applications.
        </p>

        {{-- Leave requests table --}}
        <table style="width:100%;border-collapse:collapse;margin:20px 0;font-size:13px;">
            <thead>
                <tr style="background:#f8fafc;">
                    <th style="padding:10px 12px;text-align:left;border-bottom:2px solid #e2e8f0;color:#475569;">Employee</th>
                    <th style="padding:10px 12px;text-align:left;border-bottom:2px solid #e2e8f0;color:#475569;">Leave Type</th>
                    <th style="padding:10px 12px;text-align:left;border-bottom:2px solid #e2e8f0;color:#475569;">Dates</th>
                    <th style="padding:10px 12px;text-align:center;border-bottom:2px solid #e2e8f0;color:#475569;">Days</th>
                    <th style="padding:10px 12px;text-align:left;border-bottom:2px solid #e2e8f0;color:#475569;">Applied</th>
                </tr>
            </thead>
            <tbody>
                @foreach($pendingApplications as $app)
                <tr style="border-bottom:1px solid #f1f5f9;">
                    <td style="padding:10px 12px;color:#1e293b;">
                        <strong>{{ $app->employee?->preferred_name ?? $app->employee?->full_name ?? '—' }}</strong>
                    </td>
                    <td style="padding:10px 12px;color:#1e293b;">
                        {{ $app->leaveType?->name ?? 'Leave' }}
                    </td>
                    <td style="padding:10px 12px;color:#1e293b;">
                        {{ $app->start_date->format('d M') }}–{{ $app->end_date->format('d M Y') }}
                    </td>
                    <td style="padding:10px 12px;text-align:center;color:#1e293b;">
                        {{ $app->total_days }}
                        @if($app->is_half_day) <small>(½)</small> @endif
                    </td>
                    <td style="padding:10px 12px;color:#64748b;font-size:12px;">
                        {{ $app->created_at->format('d M Y') }}
                        @php $daysWaiting = (int) $app->created_at->diffInDays(now()); @endphp
                        @if($daysWaiting >= 3)
                            <br><span style="color:#dc2626;font-weight:600;">{{ $daysWaiting }} days ago</span>
                        @elseif($daysWaiting >= 1)
                            <br><span style="color:#d97706;">{{ $daysWaiting }} day(s) ago</span>
                        @endif
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>

        {{-- CTA Button --}}
        <div style="text-align:center;margin:28px 0;">
            <a href="{{ url('/my/team-leave?status=pending') }}"
               style="display:inline-block;background:#2684FE;color:#fff;text-decoration:none;padding:12px 32px;border-radius:8px;font-weight:600;font-size:14px;">
                Review Leave Requests
            </a>
        </div>

        {{-- Legal notice --}}
        <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:14px 16px;margin-top:20px;">
            <p style="margin:0;font-size:12px;color:#92400e;line-height:1.5;">
                <strong>⚠ Employment Act 1955 (Malaysia):</strong>
                Employers are required to respond to leave applications in a timely manner.
                Unreasonable delays or denials may be subject to review by the Department of Labour.
                Annual leave (8–16 days), sick leave (14–22 days), maternity leave (98 days),
                and paternity leave (7 days) are statutory entitlements that cannot be denied without lawful reason.
            </p>
        </div>
    </div>

    {{-- Footer --}}
    <div style="background:#f8fafc;padding:20px 32px;text-align:center;border-top:1px solid #e2e8f0;">
        <p style="margin:0;color:#94a3b8;font-size:12px;">
            This is an automated reminder from the HR system.<br>
            Please do not reply to this email.
        </p>
    </div>

</div>
</body>
</html>
