<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
  body { font-family:'Segoe UI',Arial,sans-serif; background:#f1f5f9; margin:0; padding:20px; }
  .email-wrap { max-width:620px; margin:0 auto; background:#fff; border-radius:12px; overflow:hidden; box-shadow:0 2px 12px rgba(0,0,0,0.08); }
  .header { background:linear-gradient(135deg,#1e3a5f,#2563eb); padding:30px; text-align:center; }
  .header h1 { color:#fff; margin:0; font-size:20px; }
  .body { padding:30px; color:#334155; font-size:14px; line-height:1.6; }
  .info-box { background:#eff6ff; border-left:4px solid #2563eb; border-radius:0 8px 8px 0; padding:14px 18px; margin:16px 0; color:#1e3a5f; }
  .footer { background:#f8fafc; padding:18px 30px; text-align:center; font-size:12px; color:#94a3b8; }
</style>
</head>
<body>
@php
  $org = config('decommission.org_name');
  // A re-quote is a NEW revision, not a replacement — the offer Finance already refused is
  // still on record, and repeating the reason here saves them digging it out to compare.
  // Built in PHP rather than chained @if/@endif: Blade's directive pattern uses \B, so
  // `@endif@if(...)` compiles the second one through as literal text.
  $rejected = $batch->lastRejectedQuotation();
  $rejectedLine = $rejected
      ? 'Revision '.$rejected->revision.' was rejected'
          .($rejected->finance_reviewed_at ? ' on '.fmt_datetime($rejected->finance_reviewed_at) : '')
          .($rejected->finance_remarks ? ' — '.$rejected->finance_remarks : '')
      : null;
@endphp
<div class="email-wrap">
  <div class="header"><h1>E-Waste Quotation Awaiting Your Approval</h1></div>
  <div class="body">
    <p>Dear Finance Team,</p>
    <p>IT has uploaded the e-waste vendor's quotation for the following cycle. Please review and approve or reject it in the system.</p>
    <div class="info-box">
      <div><strong>Cycle:</strong> {{ $batch->batch_number }}</div>
      <div><strong>Vendor:</strong> {{ $batch->vendor?->name ?? '—' }}</div>
      <div><strong>Assets:</strong> {{ $batch->items->count() }}</div>
      {{-- Guard for null — see ewaste-final.blade.php: an uncaptured amount printed as
           "RM 0.00" reads as "the vendor pays us nothing", which is a worse lie than blank. --}}
      {{-- Keep @endif off the end of a word: Blade's directive pattern uses \B, so
           `text@endif` is NOT recognised and compiles through as literal text. --}}
      <div><strong>Offer (payable to us):</strong>
        @if($batch->quotation_amount !== null)
          RM {{ number_format((float) $batch->quotation_amount, 2) }}
        @else
          stated in the attached quotation
        @endif
      </div>
    </div>
    @if($rejectedLine)
    <div class="info-box" style="background:#fef2f2;border-left-color:#dc2626;color:#7f1d1d;">
      <div><strong>This is a revised quotation &mdash; revision {{ $batch->quotationRevisionCount() }} of this cycle.</strong></div>
      <div>{{ $rejectedLine }}</div>
      <div style="font-size:12px;">The rejected quotation stays on the cycle log and is reproduced in the report alongside this one.</div>
    </div>
    @endif
    @include('emails.partials._button', ['url' => $approveUrl, 'label' => 'Review &amp; Approve →'])
    {{-- The old "Reports → Pending E-Waste Quotations" screen was deleted on 2026-07-29; Finance's
         only surface is now Accounting → Assets with the status filter on "Disposed". Naming a
         screen that no longer exists reads as "I don't have permission" — the exact misdiagnosis
         the CSP status-filter bug already caused once. --}}
    <p style="font-size:12px;color:#94a3b8;">If the button does not work, log in and open <strong>Accounting &rarr; Assets</strong>, then set the Status filter to <strong>Disposed</strong>.</p>
  </div>
  <div class="footer">This is an automated message from {{ $org }}. Please do not reply directly to this email.</div>
</div>
</body>
</html>
