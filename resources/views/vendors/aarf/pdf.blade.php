{{-- AARF print template (dompdf).

     Section order and numbering match vendors/aarf/show.blade.php exactly, so the printed
     page reads like the page it was signed on. Change one and change the other.

     No `font-style: italic` anywhere: dompdf embeds a whole extra DejaVu face for it,
     which cost the decommission report ~370 KB per file for a single styled line. --}}
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $aarf->reference }}</title>
    <style>
        @page { margin: 28px 32px; }
        body  { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #222; }
        h1    { font-size: 15px; margin: 0 0 2px; }
        .muted    { color: #666; }
        .small    { font-size: 9px; }
        .head     { border-bottom: 2px solid #222; padding-bottom: 8px; margin-bottom: 12px; }
        .ref      { font-size: 13px; font-weight: bold; }
        .sect     { margin-top: 14px; }
        .sect-title { font-size: 10px; font-weight: bold; text-transform: uppercase;
                      letter-spacing: .6px; border-bottom: 1px solid #bbb;
                      padding-bottom: 3px; margin-bottom: 6px; }
        .sub-title  { font-size: 9px; font-weight: bold; text-transform: uppercase;
                      letter-spacing: .5px; color: #777; margin: 9px 0 4px; }
        table     { width: 100%; border-collapse: collapse; }
        /* Label-over-value cells, so the header block reads as one panel rather than a
           column of separate lines — same shape as the screen. */
        .panel td { border: 1px solid #d5dce4; padding: 5px 7px; vertical-align: top;
                    width: 33.33%; }
        .panel .k { font-size: 8px; text-transform: uppercase; letter-spacing: .4px;
                    color: #7d8994; }
        .panel .v { font-weight: bold; }
        .panel .m { font-size: 8.5px; color: #77808a; }
        .assets th, .assets td { border: 1px solid #bbb; padding: 4px 5px; text-align: left; }
        .assets th { background: #f0f0f0; font-size: 9px; text-transform: uppercase; }
        .assets td { font-size: 9px; }
        .box      { border: 1px solid #bbb; padding: 7px 9px; margin-top: 4px; }
        .rep      { border: 1px dashed #9aa7b5; padding: 8px 10px; }
        .tick     { font-size: 11px; font-weight: bold; }
    </style>
</head>
<body>

@php
    // Same direction split as the screen: a return is signed by the vendor's collector with
    // our processor replying, a receipt the other way round. Keep this in step with
    // vendors/aarf/show.blade.php — the printed page must read like the page it was signed on.
    $isReturn = $aarf->isReturn();
    $party = $isReturn ? 'Collector' : 'Receiving Staff';
    // Used by section 5 (the reply's signature) as well as section 7, so it is resolved
    // here rather than in section 7's own block further down.
    $processorSigner = \App\Models\RentalAssetAcknowledgement::actorIdentity($aarf->processorAcknowledger);
    $tickText = $isReturn
        ? 'I confirm that I have collected the assets listed above and received them in good condition and without any physical damage.'
        : 'I confirm that the assets listed above were received in good condition and without any physical damage.';
@endphp

{{-- Masthead --}}
<div class="head">
    <table>
        <tr>
            {{-- No company letterhead. The entity on this document is whoever RENTED the
                 assets, stated as "Company Rented To" in section 1 — a fixed org name here
                 (it was always Claritas) contradicted it on every other company's form. --}}
            <td>
                <h1>Asset Acceptance &amp; Return Form (AARF)</h1>
            </td>
            <td style="text-align:right;">
                <div class="muted small">{{ $aarf->isAcknowledged() ? 'Acknowledged '.fmt_date($aarf->acknowledged_at) : 'DRAFT — not yet acknowledged' }}</div>
            </td>
        </tr>
    </table>
</div>

{{-- 1 — Report Number, Type of Process, Company Rented To and the vendor, as ONE block --}}
<div class="sect">
    <div class="sect-title">1. Report Details</div>
    <table class="panel">
        <tr>
            <td>
                <div class="k">Report Number</div>
                <div class="ref">{{ $aarf->reference }}</div>
            </td>
            <td>
                <div class="k">Type of Process</div>
                <div class="v">{{ $aarf->typeLabel() }}</div>
            </td>
            <td>
                <div class="k">Company Rented To</div>
                <div class="v">{{ $aarf->company_rented_to ?: '—' }}</div>
            </td>
        </tr>
        <tr>
            <td>
                <div class="k">Date Prepared</div>
                <div class="v">{{ fmt_date($aarf->created_at) }}</div>
            </td>
            <td>
                <div class="k">Total Assets</div>
                <div class="v">{{ $aarf->items->count() }}</div>
            </td>
            <td>
                <div class="k">Status</div>
                <div class="v">{{ $aarf->statusBadge()['label'] }}</div>
            </td>
        </tr>
    </table>

    <div class="sub-title">Vendor Details</div>
    <table class="panel">
        <tr>
            <td>
                <div class="k">Vendor</div>
                <div class="v">{{ $aarf->vendor->name }}</div>
                @if($aarf->vendor->company_registration_no)
                    <div class="m">Reg. No. {{ $aarf->vendor->company_registration_no }}</div>
                @endif
            </td>
            <td>
                <div class="k">Person In Charge</div>
                <div class="v">{{ $aarf->vendor->pic_name ?: '—' }}</div>
                <div class="m">{{ collect([$aarf->vendor->pic_email, $aarf->vendor->pic_phone])->filter()->implode(' · ') ?: '—' }}</div>
            </td>
            <td>
                <div class="k">Contact</div>
                <div class="v">{{ $aarf->vendor->contact_number ?: '—' }}</div>
                @if($aarf->vendor->address)
                    <div class="m">{{ $aarf->vendor->address }}</div>
                @endif
            </td>
        </tr>
    </table>
</div>

{{-- 2 — Section A only --}}
<div class="sect">
    <div class="sect-title">2. List of Assets — Section A</div>
    <table class="assets">
        <thead>
            <tr>
                <th style="width:22px;">#</th>
                <th>Asset Tag</th>
                <th>Category</th>
                <th>Type</th>
                <th>Brand</th>
                <th>Model</th>
                <th>Serial Number</th>
            </tr>
        </thead>
        <tbody>
        @php $categories = config('asset-categories.categories', []); @endphp
        @foreach($aarf->items as $i => $item)
            <tr>
                <td>{{ $i + 1 }}</td>
                <td>{{ $item->asset_tag ?: '—' }}</td>
                <td>{{ $categories[$item->asset_category] ?? ($item->asset_category ?: '—') }}</td>
                <td>{{ $item->asset_type ?: '—' }}</td>
                <td>{{ $item->brand ?: '—' }}</td>
                <td>{{ $item->model ?: '—' }}</td>
                <td>{{ $item->serial_number ?: '—' }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
    <div class="muted small" style="margin-top:4px;">Total: {{ $aarf->items->count() }} asset{{ $aarf->items->count() === 1 ? '' : 's' }}</div>
</div>

{{-- 3 --}}
<div class="sect">
    <div class="sect-title">3. Confirmation</div>
    <div class="tick">
        [{{ $aarf->condition_confirmed ? 'X' : ' ' }}]
        {{ $tickText }}
    </div>
</div>

{{-- 4 — whoever is receiving the assets in this direction --}}
<div class="sect">
    <div class="sect-title">4. Condition Remarks — {{ $party }}</div>
    <div class="box">
        <div class="muted small">Any asset {{ $isReturn ? 'the collector is not receiving in good condition' : 'received damaged or not in good condition' }}</div>
        <div>{{ $aarf->condition_remarks ?: 'None recorded.' }}</div>
    </div>
</div>

{{-- 5 — the second party's reply, signed in its own action before the closing signatory
     locks the form. WHO that is flips with the direction, and so does the SHAPE of the
     signature: our processor on a return signs with their account (they are logged in),
     the vendor's delivery rep on a receipt signs a typed identity (they have none). --}}
@if($isReturn)
<div class="sect">
    <div class="sect-title">5. Processor's Remarks (Internal Purpose Only)</div>
    @if($aarf->processorAcknowledged())
        <div class="rep">
            <div>{{ $aarf->processor_remarks }}</div>
            <div class="muted small" style="margin-top:5px;">
                Signed by {{ $processorSigner['name'] ?? '—' }}@if(!empty($processorSigner['details'])) ({{ $processorSigner['details'] }})@endif
                on {{ fmt_datetime($aarf->processor_acknowledged_at) }}.
            </div>
        </div>
    @else
        <div class="box">
            <div class="muted">No remarks from the processor.</div>
        </div>
    @endif
</div>
@else
<div class="sect">
    <div class="sect-title">5. Vendor Representative's Remarks</div>
    @if($aarf->vendorRepAcknowledged())
        <div class="rep">
            <div>{{ $aarf->vendor_rep_remarks }}</div>
            <table class="panel" style="margin-top:6px;">
                <tr>
                    <td style="width:25%;"><div class="k">Company</div><div class="v">{{ $aarf->vendor_rep_company ?: '—' }}</div></td>
                    <td style="width:25%;"><div class="k">Name</div><div class="v">{{ $aarf->vendor_rep_name ?: '—' }}</div></td>
                    <td style="width:25%;"><div class="k">IC / Passport</div><div class="v">{{ $aarf->vendor_rep_ic ?: '—' }}</div></td>
                    <td style="width:25%;"><div class="k">Contact Number</div><div class="v">{{ $aarf->vendor_rep_phone ?: '—' }}</div></td>
                </tr>
            </table>
            <div class="muted small" style="margin-top:5px;">
                Acknowledged by {{ $aarf->vendor_rep_name }} on {{ fmt_datetime($aarf->vendor_rep_acknowledged_at) }}.
            </div>
        </div>
    @else
        <div class="box">
            <div class="muted">No remarks from the vendor representative.</div>
        </div>
    @endif
</div>
@endif

{{-- 6 --}}
<div class="sect">
    <div class="sect-title">6. Collector Details{{ $isReturn ? ' — '.$aarf->vendor->name : '' }}</div>
    <table class="panel">
        <tr>
            <td style="width:25%;"><div class="k">Company</div><div class="v">{{ $aarf->collector_company ?: '—' }}</div></td>
            <td style="width:25%;"><div class="k">Name</div><div class="v">{{ $aarf->collector_name ?: '—' }}</div></td>
            <td style="width:25%;"><div class="k">IC / Passport</div><div class="v">{{ $aarf->collector_ic ?: '—' }}</div></td>
            <td style="width:25%;"><div class="k">Phone Number</div><div class="v">{{ $aarf->collector_phone ?: '—' }}</div></td>
        </tr>
    </table>
</div>

{{-- 7 — sign-off. The closing signatory flips with the direction, and on a RETURN it is
     not the same party as the account the form was submitted from: the vendor's collector
     acknowledges (typed identity + `acknowledged_at`), and our account is the desk it was
     processed at. Both are printed. Collapsing them into one "signed by" cell would credit
     whichever party got printed with the other's act — which is exactly what the earlier
     single-signature return did. --}}
@php
    $preparer = \App\Models\RentalAssetAcknowledgement::actorIdentity($aarf->creator);
    $signer   = \App\Models\RentalAssetAcknowledgement::actorIdentity($aarf->acknowledger);
@endphp
<div class="sect">
    <div class="sect-title">7. Acknowledgement</div>
    <table class="panel">
        <tr>
            <td>
                <div class="k">Prepared By</div>
                <div class="v">{{ $preparer['name'] ?? 'Not recorded' }}</div>
                <div class="m">{{ $preparer['details'] ?? '' }}</div>
                <div class="m">{{ fmt_datetime($aarf->created_at) }}</div>
            </td>
            @if($isReturn)
            <td>
                <div class="k">Acknowledged By ({{ $aarf->vendor->name }})</div>
                <div class="v">{{ $aarf->collector_name ?: 'Not yet acknowledged' }}</div>
                <div class="m">{{ $aarf->collector_company ?: '' }}</div>
                <div class="m">{{ $aarf->collector_ic ? 'IC / Passport '.$aarf->collector_ic : '' }}</div>
                <div class="m">{{ $aarf->acknowledged_at ? fmt_datetime($aarf->acknowledged_at) : '' }}</div>
            </td>
            <td>
                <div class="k">Processed Under Account</div>
                <div class="v">{{ $signer['name'] ?? 'Not yet acknowledged' }}</div>
                <div class="m">{{ $signer['details'] ?? '' }}</div>
                <div class="m">{{ $aarf->acknowledged_at ? fmt_datetime($aarf->acknowledged_at) : '' }}</div>
            </td>
            @else
            <td>
                <div class="k">Acknowledged By (Receiving)</div>
                <div class="v">{{ $signer['name'] ?? 'Not yet acknowledged' }}</div>
                <div class="m">{{ $signer['details'] ?? '' }}</div>
                <div class="m">{{ $aarf->acknowledged_at ? fmt_datetime($aarf->acknowledged_at) : '' }}</div>
            </td>
            <td>
                <div class="k">Vendor Representative</div>
                <div class="v">{{ $aarf->vendor_rep_name ?: 'Not signed' }}</div>
                <div class="m">{{ $aarf->vendor_rep_company ?: '' }}</div>
                <div class="m">{{ $aarf->vendor_rep_acknowledged_at ? fmt_datetime($aarf->vendor_rep_acknowledged_at) : '' }}</div>
            </td>
            @endif
        </tr>
        {{-- A second row rather than a fourth cell: `.panel td` is a fixed 33.33%, so a
             fourth would squeeze the whole block. Only rendered when it happened. --}}
        @if($isReturn && $aarf->processorAcknowledged())
        <tr>
            <td>
                <div class="k">Processor's Reply Signed By</div>
                <div class="v">{{ $processorSigner['name'] ?? '—' }}</div>
                <div class="m">{{ $processorSigner['details'] ?? '' }}</div>
                <div class="m">{{ fmt_datetime($aarf->processor_acknowledged_at) }}</div>
            </td>
            <td></td>
            <td></td>
        </tr>
        @endif
    </table>
</div>

</body>
</html>
