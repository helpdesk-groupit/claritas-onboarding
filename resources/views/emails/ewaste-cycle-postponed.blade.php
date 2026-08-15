<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
  body { font-family:'Segoe UI',Arial,sans-serif; background:#f1f5f9; margin:0; padding:20px; }
  .email-wrap { max-width:680px; margin:0 auto; background:#fff; border-radius:12px; overflow:hidden; box-shadow:0 2px 12px rgba(0,0,0,0.08); }
  .header { background:linear-gradient(135deg,#991b1b,#dc2626); padding:30px; text-align:center; }
  .header h1 { color:#fff; margin:0; font-size:20px; }
  .body { padding:30px; color:#334155; font-size:14px; line-height:1.6; }
  .info-box { background:#fef2f2; border-left:4px solid #dc2626; border-radius:0 8px 8px 0; padding:14px 18px; margin:16px 0; color:#991b1b; }
  table.assets { width:100%; border-collapse:collapse; margin:18px 0; font-size:13px; }
  table.assets th { background:#f8fafc; text-align:left; padding:8px 10px; border-bottom:2px solid #e2e8f0; color:#475569; }
  table.assets td { padding:8px 10px; border-bottom:1px solid #f1f5f9; vertical-align:top; }
  .missing { color:#b91c1c; font-weight:600; }
  .btn { display:inline-block; background:#2563eb; color:#fff !important; text-decoration:none; padding:11px 22px; border-radius:8px; font-weight:600; }
  .footer { background:#f8fafc; padding:18px 30px; text-align:center; font-size:12px; color:#94a3b8; }
</style>
</head>
<body>
@php
    $org = config('decommission.org_name');
    $count = $blocking->count();
    $noun = $count === 1 ? 'asset' : 'assets';
@endphp
<div class="email-wrap">
  <div class="header"><h1>E-Waste Collection Postponed</h1></div>
  <div class="body">
    @if($audience === 'finance')
      <p>Dear Finance Team,</p>
      <p>
        The quarterly e-waste collection cycle did <strong>not</strong> run today, so there will be
        no quotation to approve this quarter. No assets were collected and none have left the inventory.
      </p>
    @else
      <p>Dear IT Team,</p>
      <p>
        The quarterly e-waste collection cycle did <strong>not</strong> run. {{ $count }} of the
        {{ $total }} assets in the Decommissioning queue {{ $count === 1 ? 'is' : 'are' }} not ready,
        and the cycle only runs when every one of them is.
      </p>
    @endif

    <div class="info-box">
      <div><strong>Assets holding the cycle:</strong> {{ $count }} of {{ $total }}</div>
      <div><strong>Next attempt:</strong> {{ fmt_date($nextSweepDate) }}</div>
      {{-- Nothing was half-done, and saying so matters: the natural worry on reading this is
           that some assets went and others did not. --}}
      <div style="margin-top:6px;">
        Nothing was collected and no cycle was created — the entire queue rolls forward intact.
      </div>
    </div>

    <table class="assets">
      <thead>
        <tr>
          <th>Asset Tag</th>
          <th>Type</th>
          <th>Brand / Model</th>
          <th>Still needed</th>
        </tr>
      </thead>
      <tbody>
      @foreach($blocking as $row)
        <tr>
          <td><strong>{{ $row->asset_tag }}</strong></td>
          <td>{{ ucfirst(str_replace('_', ' ', (string) $row->asset_type)) }}</td>
          <td>{{ trim(($row->brand ?? '').' '.($row->model ?? '')) ?: '—' }}</td>
          <td class="missing">{{ $row->isInspected() ? 'Owning company' : 'Inspection' }}</td>
        </tr>
      @endforeach
      </tbody>
    </table>

    @if($audience !== 'finance')
      <p style="text-align:center;margin:26px 0;">
        <a href="{{ route('assets.index', ['tab' => 'damaged']) }}" class="btn">Open the Decommissioning queue</a>
      </p>
      <p style="font-size:13px;color:#64748b;">
        Once every asset above is inspected, IT can run the sweep immediately from the Decommissioning
        tab rather than waiting for {{ fmt_date($nextSweepDate) }}.
      </p>
    @endif
  </div>
  <div class="footer">This is an automated message from {{ $org }}. Please do not reply directly to this email.</div>
</div>
</body>
</html>
