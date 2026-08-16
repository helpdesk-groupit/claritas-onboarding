<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
  body { font-family:'Segoe UI',Arial,sans-serif; background:#f1f5f9; margin:0; padding:20px; }
  .email-wrap { max-width:680px; margin:0 auto; background:#fff; border-radius:12px; overflow:hidden; box-shadow:0 2px 12px rgba(0,0,0,0.08); }
  .header { background:linear-gradient(135deg,#1e3a5f,#2563eb); padding:30px; text-align:center; }
  .header h1 { color:#fff; margin:0; font-size:20px; }
  .body { padding:30px; color:#334155; font-size:14px; line-height:1.6; }
  .info-box { background:#eff6ff; border-left:4px solid #2563eb; border-radius:0 8px 8px 0; padding:14px 18px; margin:16px 0; color:#1e3a5f; }
  table.q { width:100%; border-collapse:collapse; margin:18px 0; font-size:13px; }
  table.q th { background:#f8fafc; text-align:left; padding:8px 10px; border-bottom:2px solid #e2e8f0; color:#475569; }
  table.q td { padding:8px 10px; border-bottom:1px solid #f1f5f9; }
  table.q tr.rec td { background:#f0fdf4; font-weight:600; }
  .amt { text-align:right; white-space:nowrap; }
  .btn { display:inline-block; background:#2563eb; color:#fff !important; text-decoration:none; padding:11px 22px; border-radius:8px; font-weight:600; }
  .footer { background:#f8fafc; padding:18px 30px; text-align:center; font-size:12px; color:#94a3b8; }
</style>
</head>
<body>
@php $org = config('decommission.org_name'); @endphp
<div class="email-wrap">
  <div class="header"><h1>Approval Required — E-Waste Disposal</h1></div>
  <div class="body">
    <p>Dear Management,</p>
    <p>
      A quarterly e-waste disposal for <strong>{{ $batch->company ?: 'your company' }}</strong> is ready for
      your authorisation. {{ $comparison->count() }} vendor{{ $comparison->count() === 1 ? '' : 's' }}
      {{ $comparison->count() === 1 ? 'has' : 'have' }} quoted. The vendor <strong>pays us</strong> for the
      scrap, so the highest offer is the best one.
    </p>

    <div class="info-box">
      <div><strong>Cycle:</strong> {{ $batch->batch_number }}</div>
      <div><strong>Company:</strong> {{ $batch->company ?: 'not recorded' }}</div>
      <div><strong>Assets:</strong> {{ $batch->items->count() }}</div>
    </div>

    <table class="q">
      <thead>
        <tr><th>Vendor</th><th>Revision</th><th class="amt">Offer (RM)</th></tr>
      </thead>
      <tbody>
      @foreach($comparison as $q)
        <tr class="{{ $recommended && $q->id === $recommended->id ? 'rec' : '' }}">
          <td>
            {{ $q->vendorName() }}
            @if($recommended && $q->id === $recommended->id)<br><span style="font-size:11px;color:#15803d;">Recommended by IT</span>@endif
          </td>
          <td>{{ $q->revision }}</td>
          <td class="amt">{{ $q->amount !== null ? number_format((float) $q->amount, 2) : '—' }}</td>
        </tr>
      @endforeach
      </tbody>
    </table>

    @if($batch->recommendation_note)
      <p style="font-size:13px;"><strong>IT's reason:</strong> {{ $batch->recommendation_note }}</p>
    @endif

    {{-- The choice is theirs, not a rubber stamp on IT's line — say so, or the recommendation
         reads as the only option on the table. --}}
    <p>
      You may approve the recommended offer or select a different vendor's quotation when you approve.
      Finance may leave optional remarks on the same comparison, shown alongside it, but
      <strong>your decision is the only one that authorises the disposal</strong>.
    </p>

    {{-- Decommissioning, not the cycle page: the decision moved there on 2026-08-14 and the
         cycle page is IT's working surface now. A button landing somewhere with no approve
         control is worse than no button. --}}
    <p style="text-align:center;margin:26px 0;">
      <a href="{{ route('reports.decommission') }}" class="btn">Review and decide</a>
    </p>
  </div>
  <div class="footer">This is an automated message from {{ $org }}. Please do not reply directly to this email.</div>
</div>
</body>
</html>
