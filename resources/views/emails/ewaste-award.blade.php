<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
  body { font-family:'Segoe UI',Arial,sans-serif; background:#f1f5f9; margin:0; padding:20px; }
  .email-wrap { max-width:680px; margin:0 auto; background:#fff; border-radius:12px; overflow:hidden; box-shadow:0 2px 12px rgba(0,0,0,0.08); }
  .header { background:linear-gradient(135deg,#14532d,#16a34a); padding:30px; text-align:center; }
  .header h1 { color:#fff; margin:0; font-size:20px; }
  .body { padding:30px; color:#334155; font-size:14px; line-height:1.6; }
  .info-box { background:#f0fdf4; border-left:4px solid #16a34a; border-radius:0 8px 8px 0; padding:14px 18px; margin:16px 0; color:#14532d; }
  table.q { width:100%; border-collapse:collapse; margin:18px 0; font-size:13px; }
  table.q th { background:#f8fafc; text-align:left; padding:8px 10px; border-bottom:2px solid #e2e8f0; color:#475569; }
  table.q td { padding:8px 10px; border-bottom:1px solid #f1f5f9; }
  table.q tr.win td { background:#f0fdf4; font-weight:600; }
  .amt { text-align:right; white-space:nowrap; }
  .footer { background:#f8fafc; padding:18px 30px; text-align:center; font-size:12px; color:#94a3b8; }
</style>
</head>
<body>
@php
    $org = config('decommission.org_name');
    $forVendor = $audience === 'vendor';
@endphp
<div class="email-wrap">
  <div class="header"><h1>{{ $forVendor ? 'E-Waste Collection Awarded' : 'E-Waste Disposal Approved' }}</h1></div>
  <div class="body">
    @if($forVendor)
      <p>Dear {{ $winner?->vendor?->pic_name ?: 'Sir/Madam' }},</p>
      <p>
        Thank you for your quotation. We are pleased to confirm that <strong>{{ $winner?->vendorName() }}</strong>
        has been selected to collect and recycle the IT assets listed below.
      </p>
    @else
      <p>Dear IT Team,</p>
      <p>
        {{ $batch->company ?: 'Management' }} have approved the disposal for cycle
        <strong>{{ $batch->batch_number }}</strong>. Arrange collection with the vendor below, then upload
        the payment receipt to close the cycle.
      </p>
    @endif

    <div class="info-box">
      <div><strong>Reference:</strong> {{ $batch->batch_number }}</div>
      <div><strong>Company:</strong> {{ $batch->company ?: 'not recorded' }}</div>
      <div><strong>Assets to collect:</strong> {{ $batch->items->count() }}</div>
      <div><strong>Agreed amount:</strong>
        {{ $winner && $winner->amount !== null ? 'RM '.number_format((float) $winner->amount, 2) : 'as per the accepted quotation' }}
      </div>
      @if(! $forVendor)
        <div><strong>Vendor:</strong> {{ $winner?->vendorName() ?? 'not recorded' }}</div>
      @endif
    </div>

    @unless($forVendor)
      {{-- The internal copy carries the analysis; the vendor's does not. What the other
           vendors offered is our commercial information, not theirs. --}}
      <p style="margin-bottom:4px;"><strong>Offers received</strong></p>
      <table class="q">
        <thead><tr><th>Vendor</th><th class="amt">Offer (RM)</th></tr></thead>
        <tbody>
        @foreach($comparison as $q)
          <tr class="{{ $winner && $q->id === $winner->id ? 'win' : '' }}">
            <td>{{ $q->vendorName() }}{!! $winner && $q->id === $winner->id ? ' &mdash; <span style="color:#15803d;">selected</span>' : '' !!}</td>
            <td class="amt">{{ $q->amount !== null ? number_format((float) $q->amount, 2) : '—' }}</td>
          </tr>
        @endforeach
        </tbody>
      </table>

      @php
        // Naming the override explicitly: "we recommended A, management chose B" is precisely
        // the fact a reader of this mail needs, and it is invisible from the table alone.
        $overrode = $batch->recommended_quotation_id && $winner && $batch->recommended_quotation_id !== $winner->id;
      @endphp
      @if($overrode)
        <p style="font-size:13px;color:#92400e;">
          Note: management selected a different vendor from the one recommended
          ({{ $batch->recommendedQuotation?->vendorName() }}).
        </p>
      @endif

      @if($batch->management_remarks)
        <p style="font-size:13px;"><strong>Management's remarks:</strong> {{ $batch->management_remarks }}</p>
      @endif
      @if($batch->finance_status)
        <p style="font-size:13px;">
          <strong>Finance's position:</strong> {{ $batch->financeDecisionBadge()[1] }}{{ $batch->finance_remarks ? ' — '.$batch->finance_remarks : '' }}
        </p>
      @endif
    @endunless

    @if($forVendor)
      <p>Please contact our IT team to arrange a collection date. The asset list is attached to the collection paperwork and will be confirmed on site.</p>
    @endif
  </div>
  <div class="footer">This is an automated message from {{ $org }}. Please do not reply directly to this email.</div>
</div>
</body>
</html>
