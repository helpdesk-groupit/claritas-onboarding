<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Offboarding Reminder — 3 Days</title>
    <style>
        body { font-family: Arial, sans-serif; background:#f4f6f9; margin:0; padding:0; }
        .wrapper { max-width:600px; margin:30px auto; background:#fff; border-radius:10px; overflow:hidden; box-shadow:0 2px 12px rgba(0,0,0,.08); }
        .header { background:linear-gradient(135deg,#dc2626,#f97316); color:#fff; padding:28px 30px; }
        .header h2 { margin:0 0 4px; font-size:20px; }
        .header p  { margin:0; font-size:13px; opacity:.85; }
        .body { padding:28px 30px; color:#334155; }
        .info-box { background:#f1f5f9; border-radius:8px; padding:16px 20px; margin:20px 0; }
        .info-box table { width:100%; border-collapse:collapse; }
        .info-box td { padding:5px 0; font-size:14px; }
        .info-box td:first-child { color:#64748b; width:160px; font-weight:600; }
        .asset-table { width:100%; border-collapse:collapse; font-size:13px; margin-top:4px; }
        .asset-table th { background:#e2e8f0; padding:5px 8px; text-align:left; font-weight:600; }
        .asset-table td { padding:5px 8px; border-bottom:1px solid #e2e8f0; }
        .urgent { background:#fee2e2; border:1px solid #fca5a5; border-radius:8px; padding:14px 18px; font-size:13px; color:#991b1b; margin-top:16px; }
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
    $employee  = $offboarding->employee;
    $company   = $offboarding->company ?? 'the company';

    $heldAssets = collect();
    if ($employee) {
        $heldAssets = \App\Models\AssetInventory::where(function($q) use ($employee) {
            $q->where('assigned_employee_id', $employee->id);
            if ($employee->onboarding_id) {
                $ids = \App\Models\AssetAssignment::where('onboarding_id', $employee->onboarding_id)
                    ->where('status','assigned')->pluck('asset_inventory_id');
                if ($ids->isNotEmpty()) $q->orWhereIn('id', $ids);
            }
        })->whereIn('status',['assigned','unavailable'])->get();
    }
@endphp
<div class="wrapper">
    <div class="header">
        @if(!empty($__logoUrl))
        <div style="margin-bottom:12px;text-align:center;"><img src="{{ $__logoUrl }}" alt="Company Logo"
                         width="160" height="auto"
                         style="display:block;width:160px;height:auto;border:0;outline:none;text-decoration:none;margin:0 auto;"></div>
        @endif
        <h2>⏰ Offboarding Reminder — 3 Days Left</h2>
        <p>Your exit date is approaching. Please complete all offboarding requirements.</p>
    </div>
    <div class="body">
        <p>Dear <strong>{{ $offboarding->full_name }}</strong>,</p>
        <p>This is a reminder that your last working day is in <strong>3 days</strong>. Please ensure all the items below are completed before your exit date.</p>

        <div class="info-box">
            <table>
                <tr><td>Employee</td><td><strong>{{ $offboarding->full_name }}</strong></td></tr>
                <tr><td>Designation</td><td>{{ $offboarding->designation ?? '—' }}</td></tr>
                <tr><td>Department</td><td>{{ $offboarding->department ?? '—' }}</td></tr>
                <tr><td>Company</td><td>{{ $company }}</td></tr>
                <tr><td>Exit Date</td><td><strong>{{ $offboarding->exit_date?->format('d M Y') ?? '—' }}</strong></td></tr>
            </table>
        </div>

        @if($heldAssets->isNotEmpty())
        <p style="font-weight:600;margin-bottom:6px;">Assets to Return:</p>
        <table class="asset-table">
            <thead><tr><th>Asset Tag</th><th>Type</th><th>Brand / Model</th></tr></thead>
            <tbody>
                @foreach($heldAssets as $ast)
                <tr>
                    <td><strong>{{ $ast->asset_tag }}</strong></td>
                    <td>{{ ucfirst(str_replace('_',' ',$ast->asset_type ?? '')) }}</td>
                    <td>{{ $ast->brand }} {{ $ast->model }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
        @endif

        <div class="urgent" style="margin-top:16px;">
            <strong>URGENT — Please complete before your exit date:</strong>
            <ul style="margin:8px 0 0 0;padding-left:18px;">
                <li>Return all company assets listed above to the IT department immediately</li>
                <li>Complete and submit all work handover documentation</li>
                <li>Back up any personal files and remove personal data from company devices</li>
                <li>Ensure all outstanding tasks are handed over to your team</li>
            </ul>
        </div>

        <p style="margin-top:24px;font-size:13px;color:#64748b;">
            This is an automated notification. Please contact HR for any queries.
        </p>
    </div>
    <div class="footer">
        {{ $company }} &mdash; Confidential &mdash; Offboarding Reminder
    </div>
</div>
</body>
</html>