@php
    // The entity the report is issued for — the company that OWNS the assets, not the group's
    // fixed name. A cycle has been per-company since Phase 4, and this PDF is also the asset
    // list attached to the vendor's RFQ, so a fixed letterhead names the wrong party on
    // another entity's paperwork.
    $org      = $batch->issuingCompany();
    $isEwaste = $batch->isEwaste();
    // The Finance approval is a system action with no document to attach, so it is
    // signed off here — name + designation + timestamp, mirroring the eClaim convention.
    $reviewer = \App\Models\AssetDecommissionBatch::actorIdentity($batch->financeReviewer);
    // The quotation and receipt are reproduced as captioned pages at the END of the report
    // (DecommissionReportRenderer), each carrying its own uploaded timestamp + uploader — so
    // there is no document section in the body. render() hands us the appendix it is about
    // to append; we only resolve it ourselves when the view is rendered standalone. All we
    // need it for here is the honest note about anything that could NOT be reproduced.
    $appendix   = $appendix ?? \App\Services\DecommissionReportRenderer::appendix($batch);
    $unreadable = array_filter($appendix, fn ($d) => ! $d['appendable']);
    // Every quotation revision + the Finance decision on each. Empty on a cycle with no
    // quotation yet, and on one whose single quotation predates the revision table (its
    // decision is still signed off in the Finance Approval stamp below, from the batch's
    // own columns).
    $quotationHistory = $isEwaste ? $batch->quotations : collect();

    // Phase 5/6 — the authorisation trail. Management's decision is what authorised the
    // disposal; Finance's is the position recorded beside it. Both are printed, and so is the
    // accepted offer, because the report is the only place the choice made on price is
    // evidenced once the cycle is closed.
    $mgmtReviewer = \App\Models\AssetDecommissionBatch::actorIdentity($batch->managementReviewer);
    $selectedQuotation = $isEwaste ? $batch->selectedQuotation : null;
    $overrodeRecommendation = $isEwaste
        && $batch->recommended_quotation_id
        && $batch->selected_quotation_id
        && $batch->recommended_quotation_id !== $batch->selected_quotation_id;
@endphp
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    * { font-family: DejaVu Sans, sans-serif; }
    body { font-size: 11px; color: #1e293b; margin: 0; }
    .doc-head { border-bottom: 2px solid #1e3a5f; padding-bottom: 8px; margin-bottom: 12px; }
    .doc-head h1 { font-size: 17px; margin: 0 0 2px; color: #1e3a5f; }
    .muted { color: #64748b; }
    .meta td { padding: 2px 6px; font-size: 11px; }
    .meta td.k { color: #64748b; width: 130px; }
    table.items { width: 100%; border-collapse: collapse; margin: 10px 0; }
    table.items th, table.items td { border: 1px solid #cbd5e1; padding: 5px 7px; text-align: left; font-size: 10px; }
    table.items th { background: #f1f5f9; }
    .section-title { font-size: 12px; font-weight: bold; color: #1e3a5f; margin: 16px 0 4px; border-bottom: 1px solid #dbeafe; padding-bottom: 3px; }
    .stamp { border: 1px solid #cbd5e1; border-radius: 6px; padding: 10px 12px; margin-top: 6px; background: #f8fafc; }
    .stamp .ok { color: #047857; font-weight: bold; }
</style>
</head>
<body>
    <div class="doc-head">
        <h1>Asset Decommissioning Report</h1>
        <div class="muted">{{ $org }} &mdash; {{ $batch->typeLabel() }}</div>
    </div>

    <table class="meta">
        <tr><td class="k">Reference</td><td><strong>{{ $batch->batch_number }}</strong></td>
            <td class="k">Type</td><td>{{ $batch->typeLabel() }}</td></tr>
        <tr><td class="k">Vendor</td><td>{{ $batch->vendor?->name ?? '—' }}</td>
            <td class="k">Status</td><td>{{ $batch->statusBadge()[1] }}</td></tr>
        <tr><td class="k">Created</td><td>{{ fmt_datetime($batch->created_at) }}</td>
            <td class="k">Assets</td><td>{{ $batch->items->count() }}</td></tr>
    </table>

    {{-- One table, no per-asset cards — mirrors the AARF "List of Assets" table. Spec is
         built the same way as everywhere else that shows it: widest-to-narrowest, empty
         fields dropped rather than printed as dashes (AssetInventory::specSummary()).
         Completeness is unconditional per row since this report is e-waste only. --}}
    <div class="section-title">Assets</div>
    <table class="items">
        <thead><tr><th>#</th><th>Asset Tag</th><th>Type</th><th>Brand / Model</th><th>Spec</th><th>Serial No.</th><th>Completeness</th></tr></thead>
        <tbody>
            @foreach($batch->items as $i => $item)
            @php $itemCompleteness = $item->isEwaste() ? $item->completenessLabel() : null; @endphp
            <tr>
                <td>{{ $i + 1 }}</td>
                <td>{{ $item->asset_tag }}</td>
                <td>{{ ucfirst(str_replace('_', ' ', $item->asset_type ?? '—')) }}</td>
                <td>{{ trim(($item->brand ?? '').' '.($item->model ?? '')) ?: '—' }}</td>
                <td>{{ $item->asset?->specSummary() ?: '—' }}</td>
                <td>{{ $item->serial_number ?? '—' }}</td>
                <td>
                    @if($itemCompleteness)
                        {{ $item->isIncomplete() ? 'Incomplete' : 'Complete' }}
                        @if($item->isIncomplete() && $item->ewaste_parts_removed)
                            <br><span class="muted" style="font-size:9px;">Parts removed: {{ $item->ewaste_parts_removed }}</span>
                        @endif
                    @else
                        —
                    @endif
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>

    {{-- This report covers e-waste cycles only. A rental return is an Asset Acceptance &
         Return Form (RTA-…), rendered by vendors/aarf/pdf.blade.php and archived on the
         vendor profile — it archives nothing as waste and earns nothing, so it never
         belonged in a disposal report. --}}
    {{-- The re-quote loop, when there was one. This is NOT the document summary block that
         was removed (both documents are reproduced in full as captioned pages) — it is the
         trail of SYSTEM ACTIONS behind them, the same reason the Finance Approval sign-off
         below is kept: a cycle can carry two quotations at two different prices because
         Finance refused the first, and the amount each one offered is what makes the
         sequence mean anything. A single-quotation cycle prints nothing here. --}}
    @if($quotationHistory->count() > 1)
    <div class="section-title">Quotations Received &amp; Decisions</div>
    <table class="items">
        <thead><tr><th>Vendor</th><th>Rev</th><th>Offer (RM)</th><th>Uploaded</th><th>Outcome</th><th>Reason given</th></tr></thead>
        <tbody>
            @foreach($quotationHistory as $q)
            @php
                $qUploader = \App\Models\AssetDecommissionBatch::actorIdentity($q->uploader);
                $qReviewer = \App\Models\AssetDecommissionBatch::actorIdentity($q->financeReviewer);
                $isWinner = $batch->selected_quotation_id && $q->id === $batch->selected_quotation_id;
                $isRec = $batch->recommended_quotation_id && $q->id === $batch->recommended_quotation_id;
            @endphp
            <tr>
                <td>
                    {{ $q->vendorName() }}
                    @if($isRec)<br><span class="muted" style="font-size:8px;">recommended by IT</span>@endif
                </td>
                <td>{{ $q->revision }}</td>
                {{-- Never 0.00 for an uncaptured amount: that would state the vendor
                     offered nothing, instead of "the figure is in the document". --}}
                <td>{{ $q->amount !== null ? number_format((float) $q->amount, 2) : 'stated in the document' }}</td>
                <td>
                    {{ $q->uploaded_at ? fmt_datetime($q->uploaded_at) : 'date not recorded' }}
                    @if($qUploader)<br>by {{ $qUploader['name'] }}@endif
                </td>
                <td>
                    {{-- The outcome that matters here is whether this offer was the one
                         ACCEPTED, not what Finance thought of it — Finance's position is a
                         recommendation to management, whose decision is stamped below. --}}
                    @if($isWinner)
                        Accepted
                    @elseif($batch->selected_quotation_id)
                        Not selected
                    @elseif($q->isRejected())
                        Rejected
                    @elseif($q->isPending())
                        Awaiting review
                    @else
                        Not selected
                    @endif
                    @if($q->finance_reviewed_at)<br>{{ fmt_datetime($q->finance_reviewed_at) }}@endif
                    @if($qReviewer)<br>by {{ $qReviewer['name'] }}@endif
                </td>
                <td>{{ $q->finance_remarks ?: '—' }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
    <div class="muted" style="font-size:9px;">
        Every quotation listed above is reproduced in full on the following pages, including the
        offers that were not accepted &mdash; they are what evidences the choice made on price.
    </div>
    @endif

    {{-- The Quotation and Payment Receipt sections used to sit here, restating an amount
         and a filename. Both documents are now reproduced in full as captioned pages at
         the end of the report (DecommissionReportRenderer), which carry their own uploaded
         timestamp + uploader — so a summary block here would only duplicate them. The
         sign-offs stay: they are system actions with no document of their own to attach. --}}
    <div class="section-title">Authorisation</div>

    {{-- TWO sign-offs since Phase 5, and the order is deliberate: management's decision is
         what authorises the disposal, Finance's is the position recorded alongside it. A
         report showing only the Finance stamp — as this did — would name the wrong authority
         on the one page an audit reads to find out who approved writing the assets off. --}}
    <div class="stamp">
        @if($batch->management_status === 'approved')
            <div class="ok">✓ Approved by {{ $batch->company ?: 'company' }} management</div>
        @elseif($batch->management_status === 'rejected')
            <div style="color:#b91c1c;font-weight:bold;">✗ Rejected by {{ $batch->company ?: 'company' }} management</div>
        @else
            <div class="muted">Awaiting management decision.</div>
        @endif
        @if($mgmtReviewer)
            <div style="margin-top:4px;">
                {{ $batch->management_status === 'rejected' ? 'Rejected' : 'Approved' }} by:
                <strong>{{ $mgmtReviewer['name'] }}</strong>
            </div>
            @if($mgmtReviewer['details'])<div class="muted">{{ $mgmtReviewer['details'] }}</div>@endif
        @endif
        @if($batch->management_reviewed_at)
            <div class="muted">Decided {{ fmt_datetime($batch->management_reviewed_at) }}</div>
        @endif
        @if($batch->management_remarks)<div>Remarks: {{ $batch->management_remarks }}</div>@endif

        @if($selectedQuotation)
            <div style="margin-top:4px;">
                Accepted offer: <strong>{{ $selectedQuotation->vendorName() }}</strong>
                @if($selectedQuotation->amount !== null) &mdash; RM {{ number_format((float) $selectedQuotation->amount, 2) }}@endif
            </div>
            @if($overrodeRecommendation)
                {{-- The gap between what IT proposed and what was authorised is a fact the
                     report has to state; it is invisible from the table alone. --}}
                <div class="muted">Management selected a different vendor from the one IT recommended ({{ $batch->recommendedQuotation?->vendorName() }}).</div>
            @endif
        @endif

        @if($mgmtReviewer)
            {{-- Deliberately NOT italic: dompdf embeds a whole extra DejaVu face for a
                 single styled line, which added ~370 KB to every report. --}}
            <div class="muted" style="margin-top:5px;font-size:9px;">Digital sign-off — the recorded system action (name + timestamp) held in this batch's audit trail. No physical signature is required.</div>
        @endif
    </div>

    {{-- Finance does not approve or reject a cycle — since 2026-08-16 their review is
         optional remarks only, shown here beside management's decision but never itself a
         verdict. A cycle decided under the pre-2026-08-16 rule still prints the verdict it
         was actually given, because that IS what happened on that cycle. --}}
    <div class="stamp" style="margin-top:6px;">
        <div style="font-weight:bold;color:#1e3a5f;">Finance Remarks</div>
        @if($batch->financeApproved())
            <div class="ok">✓ Finance approved (legacy)</div>
        @elseif($batch->financeRejected())
            <div style="color:#b91c1c;font-weight:bold;">✗ Finance objected (legacy)</div>
        @elseif($batch->finance_remarks)
            <div>{{ $batch->finance_remarks }}</div>
        @else
            <div class="muted">No remarks left by Finance. Finance's input is optional and advisory only — it does not authorise or block the disposal.</div>
        @endif
        @if($reviewer)
            <div style="margin-top:4px;">Reviewed by: <strong>{{ $reviewer['name'] }}</strong></div>
            @if($reviewer['details'])<div class="muted">{{ $reviewer['details'] }}</div>@endif
        @endif
        @if($batch->finance_reviewed_at)
            <div class="muted">Reviewed {{ fmt_datetime($batch->finance_reviewed_at) }}</div>
        @endif
        @if(($batch->financeApproved() || $batch->financeRejected()) && $batch->finance_remarks)
            {{-- The legacy approved/rejected branches above print the VERDICT, not the
                 remarks text that went with it — print it here, same as the current 'noted'
                 branch already does as its main line. --}}
            <div>Remarks: {{ $batch->finance_remarks }}</div>
        @endif
        @if($batch->financeRejected() && $batch->management_status === 'approved')
            {{-- LEGACY only — a cycle decided under the old rule where a Finance objection
                 was a real verdict. An approval over that objection is the single most
                 audit-relevant thing this report can contain, so it is stated rather than
                 left to be inferred from two stamps that disagree. Cannot happen on a cycle
                 decided under the current rule — Finance no longer has an objection to
                 override. --}}
            <div style="margin-top:4px;">The disposal was authorised by management notwithstanding this objection.</div>
        @endif
    </div>

    @if($batch->recommendation_note)
    <div class="stamp" style="margin-top:6px;">
        <div>IT's recommendation: {{ $batch->recommendation_note }}</div>
    </div>
    @endif

    {{-- A document we could not reproduce must still be accounted for. Omitting it
         silently would read as "there was no quotation", which is a different claim. --}}
    @if($unreadable)
    <div class="section-title">Documents Not Reproduced</div>
    <div class="stamp">
        @foreach($unreadable as $doc)
        <div>{{ $doc['label'] }} — could not be reproduced in this report because {{ $doc['reason'] }}.</div>
        <div class="muted">It remains attached to the batch record and can be downloaded from the system.</div>
        @endforeach
    </div>
    @endif

    <p class="muted" style="margin-top:18px;font-size:9px;">Generated {{ fmt_datetime(now()) }} · {{ $org }} · Asset Decommissioning</p>
</body>
</html>
