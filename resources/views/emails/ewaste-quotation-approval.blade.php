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
  // A re-quote is a NEW revision, not a replacement — repeating why saves Finance digging it
  // out to compare against the new offer.
  // Built in PHP rather than chained @if/@endif: Blade's directive pattern uses \B, so
  // `@endif@if(...)` compiles the second one through as literal text.
  //
  // LEGACY first: a revision Finance rejected under the pre-2026-08-16 rule (when their
  // position doubled as a verdict). Nothing produces this any more, but a cycle already
  // carrying one must keep reading as it did.
  $rejected = $batch->lastRejectedQuotation();
  $rejectedLine = $rejected
      ? 'Revision '.$rejected->revision.' was rejected by Finance'
          .($rejected->finance_reviewed_at ? ' on '.fmt_datetime($rejected->finance_reviewed_at) : '')
          .($rejected->finance_remarks ? ' — '.$rejected->finance_remarks : '')
      : null;
  // CURRENT: Finance's own remarks on the PREVIOUS revision, so they don't repeat themselves.
  // NOT management's rejection reason — submitForApproval() clears management_remarks on
  // every resubmit, so by the time this mail is built (which happens on submit) that reason
  // is already gone; it is only ever visible transiently on the cycle page, between
  // management rejecting and IT re-uploading.
  $current = $batch->currentQuotation();
  $previous = ($current && $current->revision > 1)
      ? $batch->quotations->firstWhere('revision', $current->revision - 1)
      : null;
  $requoteLine = (! $rejectedLine && $previous && $previous->finance_remarks)
      ? 'Your remarks on revision '.$previous->revision.': '.$previous->finance_remarks
      : null;
@endphp
<div class="email-wrap">
  <div class="header"><h1>E-Waste Quotation Comparison Ready for Review</h1></div>
  <div class="body">
    <p>Dear Finance Team,</p>
    <p>IT has submitted the e-waste vendor quotation comparison for the following cycle. You do not need to approve or reject anything — {{ $batch->company ?: 'the company' }}'s management make that decision. You may optionally leave remarks in the system if you wish.</p>
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
    @if($rejectedLine || $requoteLine)
    <div class="info-box" style="background:#fef2f2;border-left-color:#dc2626;color:#7f1d1d;">
      <div><strong>This is a revised quotation &mdash; revision {{ $batch->quotationRevisionCount() }} of this cycle.</strong></div>
      <div>{{ $rejectedLine ?: $requoteLine }}</div>
      <div style="font-size:12px;">The earlier offer stays on the cycle log and is reproduced in the report alongside this one.</div>
    </div>
    @endif
    @include('emails.partials._button', ['url' => $approveUrl, 'label' => 'Review &amp; Add Remarks →'])
    {{-- Finance's review surface moved to Management → Decommissioning on 2026-08-14 (off
         Accounting → Assets → "Disposed", which was itself the 2026-07-29 replacement for a
         screen deleted before that). Naming a screen that no longer exists reads as "I don't
         have permission" — the exact misdiagnosis the CSP status-filter bug already caused
         once, so keep this in step with wherever the page actually is. --}}
    <p style="font-size:12px;color:#94a3b8;">If the button does not work, log in and open <strong>Management &rarr; Decommissioning</strong>.</p>
  </div>
  <div class="footer">This is an automated message from {{ $org }}. Please do not reply directly to this email.</div>
</div>
</body>
</html>
