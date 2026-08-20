<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
  body { font-family:'Segoe UI',Arial,sans-serif; background:#f1f5f9; margin:0; padding:20px; }
  .email-wrap { max-width:620px; margin:0 auto; background:#fff; border-radius:12px; overflow:hidden; box-shadow:0 2px 12px rgba(0,0,0,0.08); }
  .header { background:linear-gradient(135deg,#047857,#10b981); padding:30px; text-align:center; }
  .header h1 { color:#fff; margin:0; font-size:20px; }
  .body { padding:30px; color:#334155; font-size:14px; line-height:1.6; }
  .info-box { background:#ecfdf5; border-left:4px solid #10b981; border-radius:0 8px 8px 0; padding:14px 18px; margin:16px 0; color:#065f46; }
  .footer { background:#f8fafc; padding:18px 30px; text-align:center; font-size:12px; color:#94a3b8; }
</style>
</head>
<body>
@php
  $org = config('decommission.org_name');
  $greeting = match($audience ?? 'finance') {
      'it' => 'IT Team',
      'management' => ($batch->company ?: 'Management').' Management',
      default => 'Finance Team',
  };
@endphp
<div class="email-wrap">
  <div class="header"><h1>E-Waste Cycle Completed — Final Report ✓</h1></div>
  <div class="body">
    <p>Dear {{ $greeting }},</p>
    <p>The following e-waste decommissioning cycle is complete. The full audit trail — asset list, vendor quotation, and payment receipt — is attached as a PDF.</p>
    <div class="info-box">
      <div><strong>Cycle:</strong> {{ $batch->batch_number }}</div>
      <div><strong>Vendor:</strong> {{ $batch->vendor?->name ?? '—' }}</div>
      <div><strong>Assets decommissioned:</strong> {{ $batch->items->count() }}</div>
      {{-- Guard for null: amounts are no longer captured on upload (2026-07-29), and
           `(float) null` printed "RM 0.00" — telling Finance the vendor paid us nothing.
           A missing figure must point at the document, never read as zero. --}}
      {{-- Keep @endif off the end of a word: Blade's directive pattern uses \B, so
           `report@endif` is NOT recognised and compiles through as literal text. --}}
      <div><strong>Quotation (offered):</strong>
        @if($batch->quotation_amount !== null)
          RM {{ number_format((float) $batch->quotation_amount, 2) }}
        @else
          see the quotation reproduced in the attached report
        @endif
      </div>
      <div><strong>Payment received (receipt):</strong>
        @if($batch->receipt_amount !== null)
          RM {{ number_format((float) $batch->receipt_amount, 2) }}
        @else
          see the receipt reproduced in the attached report
        @endif
      </div>
    </div>
    <p>These assets have been archived out of the active inventory. The full record is available on the Company Asset Decommissioning tab of the Asset Listing.</p>
  </div>
  <div class="footer">This is an automated message from {{ $org }}. Please do not reply directly to this email.</div>
</div>
</body>
</html>
