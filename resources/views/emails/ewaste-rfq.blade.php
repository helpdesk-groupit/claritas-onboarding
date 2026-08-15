<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
  body { font-family:'Segoe UI',Arial,sans-serif; background:#f1f5f9; margin:0; padding:20px; }
  .email-wrap { max-width:620px; margin:0 auto; background:#fff; border-radius:12px; overflow:hidden; box-shadow:0 2px 12px rgba(0,0,0,0.08); }
  .header { background:linear-gradient(135deg,#0f172a,#334155); padding:30px; text-align:center; }
  .header h1 { color:#fff; margin:0; font-size:20px; }
  .body { padding:30px; color:#334155; font-size:14px; line-height:1.6; }
  table.assets { width:100%; border-collapse:collapse; margin:14px 0; font-size:13px; }
  table.assets th, table.assets td { border:1px solid #e2e8f0; padding:6px 8px; text-align:left; }
  table.assets th { background:#f8fafc; }
  .footer { background:#f8fafc; padding:18px 30px; text-align:center; font-size:12px; color:#94a3b8; }
</style>
</head>
<body>
{{-- The entity whose assets these are, NOT the group's fixed name: the vendor is being asked
     to quote on one company's assets and told who will be paid, and a cycle has been
     per-company since Phase 4. --}}
@php $org = $batch->issuingCompany(); @endphp
<div class="email-wrap">
  <div class="header"><h1>Request for Quotation — E-Waste Disposal</h1></div>
  <div class="body">
    {{-- $recipient, not $batch->vendor: the RFQ goes to every e-waste vendor, and the batch's
         vendor is only a default until management pick a winner. --}}
    <p>Dear {{ $recipient?->pic_name ?: ($recipient?->name ?? 'Sir/Madam') }},</p>
    <p>{{ config('decommission.copy.rfq_intro') }}</p>
    {{-- Company is stated, not implied by the reference token: the sweep raises one cycle per
         company, so a vendor can receive several of these in one morning differing only by
         the -CLA / -ENL suffix. --}}
    <p><strong>Reference:</strong> {{ $batch->batch_number }} &nbsp;|&nbsp; <strong>Company:</strong> {{ $org }} &nbsp;|&nbsp; <strong>Assets:</strong> {{ $batch->items->count() }}</p>

    <table class="assets">
      <thead><tr><th>Asset Tag</th><th>Type</th><th>Brand / Model</th><th>Serial No.</th></tr></thead>
      <tbody>
        @foreach($batch->items as $item)
        <tr>
          <td>{{ $item->asset_tag }}</td>
          <td>{{ ucfirst(str_replace('_',' ', $item->asset_type ?? '—')) }}</td>
          <td>{{ trim(($item->brand ?? '').' '.($item->model ?? '')) ?: '—' }}</td>
          <td>{{ $item->serial_number ?? '—' }}</td>
        </tr>
        @endforeach
      </tbody>
    </table>

    <p>A detailed asset list is attached as a PDF. Kindly reply with your quotation (scrap/recovery value payable to {{ $org }}) at your earliest convenience.</p>
  </div>
  <div class="footer">This is an automated message from {{ $org }}. Please reply with your quotation.</div>
</div>
</body>
</html>
