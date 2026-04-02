<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Asset Form</title>
    <style>
        body { font-family: Arial, sans-serif; background:#f4f6f9; margin:0; padding:0; }
        .wrapper { max-width:600px; margin:30px auto; background:#fff; border-radius:10px; overflow:hidden; box-shadow:0 2px 12px rgba(0,0,0,.08); }
        .header { background:linear-gradient(135deg,#1e3a5f,#2563eb); color:#fff; padding:28px 30px; }
        .header h2 { margin:0 0 4px; font-size:20px; }
        .header p  { margin:0; font-size:13px; opacity:.85; }
        .body { padding:28px 30px; color:#334155; }
        .info-box { background:#f1f5f9; border-radius:8px; padding:16px 20px; margin:20px 0; }
        .info-box table { width:100%; border-collapse:collapse; }
        .info-box td { padding:5px 0; font-size:14px; }
        .info-box td:first-child { color:#64748b; width:140px; font-weight:600; }
        .btn-ack { display:inline-block; background:#F5A623; color:#000;
                   text-decoration:none; padding:13px 28px; border-radius:8px; font-weight:700;
                   font-size:15px; margin:20px 0; }
        .footer { background:#f8fafc; padding:16px 30px; font-size:12px; color:#94a3b8; text-align:center; border-top:1px solid #e2e8f0; }
        .notice { background:#fffbeb; border:1px solid #fcd34d; border-radius:8px; padding:14px 18px; font-size:13px; color:#92400e; margin-top:16px; }
        .asset-table { width:100%; border-collapse:collapse; font-size:13px; margin-top:4px; }
        .asset-table th { background:#e2e8f0; padding:5px 8px; text-align:left; font-weight:600; }
        .asset-table td { padding:5px 8px; border-bottom:1px solid #e2e8f0; }
    </style>
</head>
<body>
@php
    $__logoUrl = null;
    // Resolve company from employee via AARF
    $__aerfEmp = null;
    if ($aarf->employee_id) {
        $__aerfEmp = \App\Models\Employee::find($aarf->employee_id);
    }
    if (!$__aerfEmp && $aarf->onboarding_id) {
        $__aerfEmp = \App\Models\Employee::where('onboarding_id', $aarf->onboarding_id)->first();
    }
    $__logoCompany = $__aerfEmp?->company
        ? \App\Models\Company::where('name', $__aerfEmp->company)->first()
        : null;
    if ($__logoCompany?->logo_path) {
        $__logoUrl = asset('storage/' . $__logoCompany->logo_path);
    }
@endphp
<div class="wrapper">
    <div class="header">
        @if(!empty($__logoUrl))
        <div style="margin-bottom:12px;text-align:center;"><img src="{{ $__logoUrl }}" alt="Company Logo"
                         width="160" height="auto"
                         style="display:block;width:160px;height:auto;border:0;outline:none;text-decoration:none;margin:0 auto;"></div>
        @endif
        @if($actionLabel === 'returned')
            <h2>Asset Return Notification</h2>
        @else
            <h2>Asset Acknowledgement Required</h2>
        @endif
        <p>Asset Acceptance &amp; Return Form &mdash; Reference: {{ $aarf->aarf_reference }}</p>
    </div>
    <div class="body">
        <p>Dear <strong>{{ $employeeName }}</strong>,</p>

        @if($actionLabel === 'assigned')
            <p>IT has assigned asset(s) to you. Please review the details below and acknowledge receipt by clicking the button below.</p>
        @elseif($actionLabel === 'returned')
            <p>This is to notify you that an asset has been released from your Asset Acceptance &amp; Return Form record.</p>
        @else
            <p>Your Asset Acceptance &amp; Return Form has been updated. Please review the details and acknowledge.</p>
        @endif

        <div class="info-box">
            <table>
                <tr>
                    <td>Employee</td>
                    <td>{{ $employeeName }}</td>
                </tr>
                <tr>
                    <td>AARF Reference</td>
                    <td><strong>{{ $aarf->aarf_reference }}</strong></td>
                </tr>

                @if($actionLabel === 'returned')
                @php
                    $returnedRows = \App\Models\AssetAssignment::with('asset')
                        ->where(function($q) use ($aarf) {
                            if ($aarf->onboarding_id) {
                                $q->where('onboarding_id', $aarf->onboarding_id);
                            }
                            if ($aarf->employee_id) {
                                $q->orWhere('employee_id', $aarf->employee_id);
                            }
                        })
                        ->where('status', 'returned')
                        ->whereDate('returned_date', today())
                        ->get();
                    if ($returnedRows->isEmpty()) {
                        $returnedRows = \App\Models\AssetAssignment::with('asset')
                            ->where(function($q) use ($aarf) {
                                if ($aarf->onboarding_id) {
                                    $q->where('onboarding_id', $aarf->onboarding_id);
                                }
                                if ($aarf->employee_id) {
                                    $q->orWhere('employee_id', $aarf->employee_id);
                                }
                            })
                            ->where('status', 'returned')
                            ->orderByDesc('returned_date')
                            ->take(1)
                            ->get();
                    }
                @endphp
                <tr>
                    <td style="vertical-align:top;">Returned Asset(s)</td>
                    <td>
                        @if($returnedRows->isEmpty())
                            &mdash;
                        @else
                            <table class="asset-table">
                                <thead>
                                    <tr>
                                        <th>Asset Tag</th>
                                        <th>Type</th>
                                        <th>Return Date</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($returnedRows as $row)
                                    @if($row->asset)
                                    <tr>
                                        <td><strong>{{ $row->asset->asset_tag }}</strong></td>
                                        <td>{{ ucfirst(str_replace('_',' ',$row->asset->asset_type ?? '')) }}</td>
                                        <td style="color:#64748b;">{{ $row->returned_date ? \Carbon\Carbon::parse($row->returned_date)->format('d M Y') : '—' }}</td>
                                    </tr>
                                    @endif
                                    @endforeach
                                </tbody>
                            </table>
                        @endif
                    </td>
                </tr>
                @endif

                <tr>
                    <td style="vertical-align:top;">Assets Assigned</td>
                    <td>
                        @php
                            $currentAssets = \App\Models\AssetInventory::where('status', 'assigned')
                                ->where(function($q) use ($aarf) {
                                    if ($aarf->onboarding_id) {
                                        $q->whereHas('assignments', fn($sub) => $sub->where('onboarding_id', $aarf->onboarding_id)->where('status', 'assigned'));
                                    }
                                    if ($aarf->employee_id) {
                                        $q->orWhereHas('assignments', fn($sub) => $sub->where('employee_id', $aarf->employee_id)->where('status', 'assigned'));
                                    }
                                })->get();
                        @endphp
                        @if($currentAssets->isEmpty())
                            &mdash;
                        @else
                            @foreach($currentAssets as $inv)
                                {{ $inv->asset_tag }} &mdash; {{ ucfirst(str_replace('_',' ',$inv->asset_type ?? '')) }}<br>
                            @endforeach
                        @endif
                    </td>
                </tr>
            </table>
        </div>

        @if($actionLabel === 'returned')
        <p>You may view your updated Asset Acceptance &amp; Return Form by clicking the link below:</p>
        <!--[if mso]>
        <v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word"
          href="{{ route('aarf.view', $aarf->acknowledgement_token) }}"
          style="height:44px;v-text-anchor:middle;width:220px;" arcsize="10%"
          fillcolor="#F5A623" strokecolor="#F5A623">
          <w:anchorlock/>
          <center style="color:#000000;font-family:Arial,sans-serif;font-size:14px;font-weight:bold;">View AARF</center>
        </v:roundrect>
        <![endif]-->
        <!--[if !mso]><!-->
        <a href="{{ route('aarf.view', $aarf->acknowledgement_token) }}" class="btn-ack">
            View AARF
        </a>
        <!--<![endif]-->
        <div class="notice">
            <strong>Note:</strong> This is a notification only. No acknowledgement is required unless you have remaining assets still assigned to you.
        </div>
        @else
        <p>Click the button below to view your full AARF and acknowledge receipt of the listed assets:</p>
        <!--[if mso]>
        <v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word"
          href="{{ route('aarf.view', $aarf->acknowledgement_token) }}"
          style="height:44px;v-text-anchor:middle;width:280px;" arcsize="10%"
          fillcolor="#F5A623" strokecolor="#F5A623">
          <w:anchorlock/>
          <center style="color:#000000;font-family:Arial,sans-serif;font-size:14px;font-weight:bold;">View &amp; Acknowledge AARF</center>
        </v:roundrect>
        <![endif]-->
        <!--[if !mso]><!-->
        <a href="{{ route('aarf.view', $aarf->acknowledgement_token) }}" class="btn-ack">
            View &amp; Acknowledge AARF
        </a>
        <!--<![endif]-->
        <div class="notice">
            <strong>Note:</strong> By acknowledging, you confirm you have received the assets in good working condition.
            These assets remain the property of your employer and must be returned upon resignation or end of employment.
        </div>
        @endif

        <p style="margin-top:24px;font-size:13px;color:#64748b;">
            If you have any questions, please contact the IT department directly.
        </p>
    </div>
    <div class="footer">
        Confidential &mdash; {{ $aarf->aarf_reference }}
    </div>
</div>
</body>
</html>