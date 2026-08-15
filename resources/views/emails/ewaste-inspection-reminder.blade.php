<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
  body { font-family:'Segoe UI',Arial,sans-serif; background:#f1f5f9; margin:0; padding:20px; }
  .email-wrap { max-width:680px; margin:0 auto; background:#fff; border-radius:12px; overflow:hidden; box-shadow:0 2px 12px rgba(0,0,0,0.08); }
  .header { padding:30px; text-align:center; }
  .header h1 { color:#fff; margin:0; font-size:20px; }
  .body { padding:30px; color:#334155; font-size:14px; line-height:1.6; }
  .info-box { border-left:4px solid; border-radius:0 8px 8px 0; padding:14px 18px; margin:16px 0; }
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
    $urgent = $mark === 'day';
    // Day-of is red because by then the list IS the postponement; the earlier marks are amber
    // notice, not alarm.
    $headBg  = $urgent ? 'linear-gradient(135deg,#991b1b,#dc2626)' : 'linear-gradient(135deg,#b45309,#f59e0b)';
    $boxBg   = $urgent ? '#fef2f2' : '#fffbeb';
    $boxLine = $urgent ? '#dc2626' : '#f59e0b';
    $boxText = $urgent ? '#991b1b' : '#92400e';
    $count   = $rows->count();
    $noun    = $count === 1 ? 'asset' : 'assets';
@endphp
<div class="email-wrap">
  <div class="header" style="background:{{ $headBg }};">
    <h1>
      @if($urgent)
        E-Waste Collection Is Today — {{ $count }} {{ $noun }} still uninspected
      @else
        E-Waste Inspection Due — {{ $markLabel }} to the quarterly collection
      @endif
    </h1>
  </div>
  <div class="body">
    @if($audience === 'finance')
      <p>Dear Finance Team,</p>
      <p>
        The next quarterly e-waste collection cycle is due on <strong>{{ fmt_date($sweepDate) }}</strong>.
        This is advance notice so you know a quotation will be coming for approval — no action is needed from you yet.
      </p>
    @else
      <p>Dear IT Team,</p>
      <p>
        @if($urgent)
          Today is the quarterly e-waste collection day, and the {{ $count }} {{ $noun }} below
          {{ $count === 1 ? 'has' : 'have' }} not been fully inspected.
        @else
          The quarterly e-waste collection is due on <strong>{{ fmt_date($sweepDate) }}</strong> —
          <strong>{{ $markLabel }}</strong> from now. {{ $count }} {{ $noun }} in the Decommissioning queue
          still {{ $count === 1 ? 'needs' : 'need' }} inspecting.
        @endif
      </p>
    @endif

    <div class="info-box" style="background:{{ $boxBg }};border-left-color:{{ $boxLine }};color:{{ $boxText }};">
      <div><strong>Collection date:</strong> {{ fmt_date($sweepDate) }}</div>
      <div><strong>Assets awaiting inspection:</strong> {{ $count }}</div>
      {{-- The rule is worth restating on every reminder: it is the reason the list matters,
           and it is the part people are surprised by if they only hear it once. --}}
      <div style="margin-top:6px;">
        The cycle only runs once <strong>every</strong> queued asset has been inspected and its owning
        company confirmed. If any remain, the whole collection is postponed to the next quarter.
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
      @foreach($rows as $row)
        <tr>
          <td><strong>{{ $row->asset_tag }}</strong></td>
          <td>{{ ucfirst(str_replace('_', ' ', (string) $row->asset_type)) }}</td>
          <td>{{ trim(($row->brand ?? '').' '.($row->model ?? '')) ?: '—' }}</td>
          {{-- Naming WHICH half is missing is the difference between a list somebody can act
               on and one they have to open the system to understand. --}}
          <td class="missing">
            @if(! $row->isInspected())
              Inspection
            @else
              Owning company
            @endif
          </td>
        </tr>
      @endforeach
      </tbody>
    </table>

    @if($audience !== 'finance')
      <p style="text-align:center;margin:26px 0;">
        <a href="{{ route('assets.index', ['tab' => 'damaged']) }}" class="btn">Open the Decommissioning queue</a>
      </p>
    @endif
  </div>
  <div class="footer">This is an automated message from {{ $org }}. Please do not reply directly to this email.</div>
</div>
</body>
</html>
