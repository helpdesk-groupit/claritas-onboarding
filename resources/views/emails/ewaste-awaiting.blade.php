<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
  body { font-family:'Segoe UI',Arial,sans-serif; background:#f1f5f9; margin:0; padding:20px; }
  .email-wrap { max-width:620px; margin:0 auto; background:#fff; border-radius:12px; overflow:hidden; box-shadow:0 2px 12px rgba(0,0,0,0.08); }
  .header { background:linear-gradient(135deg,#b45309,#f59e0b); padding:30px; text-align:center; }
  .header h1 { color:#fff; margin:0; font-size:20px; }
  .body { padding:30px; color:#334155; font-size:14px; line-height:1.6; }
  .info-box { background:#fffbeb; border-left:4px solid #f59e0b; border-radius:0 8px 8px 0; padding:14px 18px; margin:16px 0; color:#92400e; }
  .footer { background:#f8fafc; padding:18px 30px; text-align:center; font-size:12px; color:#94a3b8; }
</style>
</head>
<body>
@php $org = config('decommission.org_name'); @endphp
<div class="email-wrap">
  <div class="header"><h1>E-Waste Cycle Opened — Assets Awaiting Decommissioning</h1></div>
  <div class="body">
    <p>Dear Finance Team,</p>
    <p>A new quarterly e-waste decommissioning cycle has been opened. The full asset list is attached as a PDF for your records.</p>
    <div class="info-box">
      <div><strong>Cycle:</strong> {{ $batch->batch_number }}</div>
      <div><strong>Assets awaiting decommissioning:</strong> {{ $batch->items->count() }}</div>
      <div><strong>Primary e-waste vendor:</strong> {{ $batch->vendor?->name ?? 'Not set — RFQ not sent' }}</div>
    </div>
    <p>The vendor has been sent a Request for Quotation. Once IT uploads the vendor's quotation, you will be asked to approve it in the system.</p>
  </div>
  <div class="footer">This is an automated message from {{ $org }}. Please do not reply directly to this email.</div>
</div>
</body>
</html>
