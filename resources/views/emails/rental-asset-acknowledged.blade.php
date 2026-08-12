<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
  body { font-family:'Segoe UI',Arial,sans-serif; background:#f1f5f9; margin:0; padding:20px; }
  .email-wrap { max-width:640px; margin:0 auto; background:#fff; border-radius:12px; overflow:hidden; box-shadow:0 2px 12px rgba(0,0,0,0.08); }
  .header { background:linear-gradient(135deg,#047857,#10b981); padding:28px; text-align:center; }
  .header h1 { color:#fff; margin:0; font-size:20px; }
  .body { padding:28px; color:#334155; font-size:14px; line-height:1.6; }
  .info-box { background:#ecfdf5; border-left:4px solid #10b981; border-radius:0 8px 8px 0; padding:14px 18px; margin:16px 0; font-size:14px; color:#065f46; }
  .warn-box { background:#fffbeb; border-left:4px solid #f59e0b; border-radius:0 8px 8px 0; padding:14px 18px; margin:16px 0; font-size:14px; color:#78350f; }
  table.assets { width:100%; border-collapse:collapse; margin-top:8px; font-size:13px; }
  table.assets th, table.assets td { border:1px solid #dbe2ea; padding:6px 8px; text-align:left; }
  table.assets th { background:#f4f7fa; font-size:11px; text-transform:uppercase; letter-spacing:.4px; color:#55606d; }
  .footer { background:#f8fafc; padding:18px 28px; text-align:center; font-size:12px; color:#94a3b8; }
</style>
</head>
<body>
@php
    // The entity on this document is whoever RENTED the assets, not a fixed org name.
    $company = $aarf->company_rented_to ?: 'the company';
    $isReturn = $aarf->isReturn();
    $greeting = match ($audience) {
        \App\Mail\RentalAssetAcknowledgedMail::AUDIENCE_VENDOR => $aarf->vendor?->pic_name ?: ($aarf->vendor?->name ?: 'Sir/Madam'),
        \App\Mail\RentalAssetAcknowledgedMail::AUDIENCE_FINANCE => 'Finance Team',
        default => 'IT Team',
    };
@endphp
<div class="email-wrap">
  <div class="header"><h1>Asset Acceptance &amp; Return Form — {{ $isReturn ? 'Return' : 'Receipt' }} Acknowledged &#10003;</h1></div>
  <div class="body">
    <p>Dear {{ $greeting }},</p>

    @if($audience === \App\Mail\RentalAssetAcknowledgedMail::AUDIENCE_VENDOR)
      @if($isReturn)
      <p>
        This confirms that the rental asset(s) listed below were <strong>collected from
        {{ $company }}</strong> and returned to {{ $aarf->vendor->name }}. The signed form is
        attached as a PDF for your records.
      </p>
      @else
      <p>
        This confirms that <strong>{{ $company }}</strong> has received and acknowledged the
        rental asset(s) listed below, supplied by {{ $aarf->vendor->name }}. The signed form
        is attached as a PDF for your records.
      </p>
      @endif
    @else
      <p>
        The Asset Acceptance &amp; Return Form <strong>{{ $aarf->reference }}</strong> has been
        acknowledged. The signed form is attached as a PDF.
        @if($isReturn)
          The {{ $aarf->items->count() }} asset{{ $aarf->items->count() === 1 ? ' has' : 's have' }}
          been archived out of the inventory.
        @endif
      </p>
    @endif

    <div class="info-box">
      <div><strong>Report Number:</strong> {{ $aarf->reference }}</div>
      <div><strong>Type of Process:</strong> {{ $aarf->typeLabel() }}</div>
      <div><strong>Vendor:</strong> {{ $aarf->vendor->name }}</div>
      <div><strong>Company Rented To:</strong> {{ $aarf->company_rented_to ?: '—' }}</div>
      <div><strong>Assets:</strong> {{ $aarf->items->count() }}</div>
      <div><strong>{{ $isReturn ? 'Collected by' : 'Acknowledged by' }}:</strong> {{ $aarf->collector_name ?: '—' }}{{ $aarf->collector_ic ? ' (IC: '.$aarf->collector_ic.')' : '' }}</div>
      @if($isReturn)
      {{-- On a return the collector is the vendor's courier, so the account that ran the
           handover is a SEPARATE line — without it the mail would name only the vendor's
           own person and leave "who on our side processed this" out of the record. --}}
      <div><strong>Processed by:</strong> {{ \App\Models\RentalAssetAcknowledgement::actorIdentity($aarf->acknowledger)['name'] ?? '—' }}</div>
      @endif
      <div><strong>Acknowledged at:</strong> {{ fmt_datetime($aarf->acknowledged_at) }}</div>
    </div>

    {{-- Damage is the one thing a summary must not bury: if it is only in the attachment,
         nobody opens the attachment. Which party raised it and which replied swaps with the
         direction — see RentalAssetAcknowledgement::secondPartyRemarks(). --}}
    @php
        // Either party's statement is worth surfacing on its own. Nesting the reply inside
        // `@if($aarf->condition_remarks)` dropped it whenever the other side left their box
        // blank — and on a return that is the common case: nothing arrived damaged, but our
        // processor still noted what was handed over.
        $secondParty = $isReturn
            ? $aarf->processor_remarks
            : ($aarf->vendorRepAcknowledged() ? $aarf->vendor_rep_remarks : null);
    @endphp
    @if($aarf->condition_remarks || $secondParty)
    <div class="warn-box">
      @if($aarf->condition_remarks)
        <div><strong>Condition remarks ({{ $isReturn ? 'collector' : 'receiving staff' }}):</strong></div>
        <div>{{ $aarf->condition_remarks }}</div>
      @endif
      @if($secondParty)
        <div style="margin-top:{{ $aarf->condition_remarks ? '10px' : '0' }};">
          <strong>{{ $isReturn ? 'Our processor’s remarks' : 'Vendor representative’s reply' }}:</strong>
        </div>
        <div>{{ $secondParty }}</div>
        @if(! $isReturn)
        <div style="margin-top:6px; font-size:12px;">
          Signed by {{ $aarf->vendor_rep_name }}@if($aarf->vendor_rep_company), {{ $aarf->vendor_rep_company }}@endif
          on {{ fmt_datetime($aarf->vendor_rep_acknowledged_at) }}.
        </div>
        @endif
      @endif
    </div>
    @endif

    <table class="assets">
      <thead>
        <tr><th>Asset Tag</th><th>Description</th><th>Serial Number</th></tr>
      </thead>
      <tbody>
      @foreach($aarf->items as $item)
        <tr>
          <td>{{ $item->asset_tag ?: '—' }}</td>
          <td>{{ $item->description() }}</td>
          <td>{{ $item->serial_number ?: '—' }}</td>
        </tr>
      @endforeach
      </tbody>
    </table>
  </div>
  <div class="footer">This is an automated message. Please do not reply directly to this email.</div>
</div>
</body>
</html>
