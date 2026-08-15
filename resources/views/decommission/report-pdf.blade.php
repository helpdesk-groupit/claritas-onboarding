@php
    use Illuminate\Support\Facades\Storage;
    // Asset photos live on the PUBLIC disk — base64-embed them (dompdf never fetches over the network).
    $pubImg = function ($path) {
        try {
            if (! $path || ! Storage::disk('public')->exists($path)) return null;
            $mime = Storage::disk('public')->mimeType($path);
            if (! str_starts_with((string) $mime, 'image/')) return null;
            return 'data:'.$mime.';base64,'.base64_encode(Storage::disk('public')->get($path));
        } catch (\Throwable $e) { return null; }
    };
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
    /* Per-asset detail cards — mirror the collector's acknowledgement form */
    .asset-card { border: 1px solid #cbd5e1; border-radius: 6px; padding: 8px 10px; margin: 8px 0; background: #f8fafc; page-break-inside: avoid; }
    .asset-card .tag { font-weight: bold; font-size: 11px; color: #1e3a5f; margin-bottom: 5px; }
    table.spec { width: 100%; border-collapse: collapse; }
    table.spec td.col { width: 50%; vertical-align: top; padding-right: 12px; }
    .spec-title { font-size: 9px; text-transform: uppercase; letter-spacing: .04em; color: #64748b; font-weight: bold; margin-bottom: 2px; }
    table.kv { width: 100%; border-collapse: collapse; }
    table.kv td { padding: 1px 0; font-size: 10px; vertical-align: top; }
    table.kv td.k { color: #64748b; width: 42%; }
    .photos-title { font-size: 9px; text-transform: uppercase; letter-spacing: .04em; color: #64748b; font-weight: bold; margin: 7px 0 3px; }
    img.photo { width: 96px; height: 72px; margin: 0 4px 4px 0; border: 1px solid #e2e8f0; border-radius: 4px; }
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

    <div class="section-title">Assets</div>
    <table class="items">
        <thead><tr><th>#</th><th>Asset Tag</th><th>Type</th><th>Brand / Model</th><th>Serial No.</th></tr></thead>
        <tbody>
            @foreach($batch->items as $i => $item)
            <tr>
                <td>{{ $i + 1 }}</td>
                <td>{{ $item->asset_tag }}</td>
                <td>{{ ucfirst(str_replace('_', ' ', $item->asset_type ?? '—')) }}</td>
                <td>{{ trim(($item->brand ?? '').' '.($item->model ?? '')) ?: '—' }}</td>
                <td>{{ $item->serial_number ?? '—' }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    {{-- Per-asset detail cards — specs, condition, notes, and photos. Rendered for
         BOTH flows so the finance report and the vendor RFQ carry the full details. --}}
    <div class="section-title">Asset Details</div>
    @foreach($batch->items as $item)
    @php
        $a          = $item->asset;
        $photos     = $a?->asset_photos ?? [];
        $condition  = $a?->conditionLabel()
            ?? ($item->asset_condition ? ucfirst(str_replace('_', ' ', (string) $item->asset_condition)) : null);
        $specOthers = trim((string) ($a?->spec_others ?? ''));
        $notes      = trim((string) ($a?->notes ?? ''));
        $reason     = trim((string) ($item->reason ?? ''));
        // `remarks` is deliberately NOT rendered: on both AssetInventory and its
        // DisposedAsset snapshot it is the machine-appended audit log (appendRemark()),
        // which ran to dozens of assign/return lines and buried the report. The
        // human-written `notes` field is the only narrative the report carries.
        // E-waste completeness drives the vendor's price — state it explicitly,
        // listing exactly which parts were removed when the asset is incomplete.
        $completeness = null;
        if ($item->isEwaste() && $item->completenessLabel()) {
            if ($item->isIncomplete()) {
                $partsRemoved = trim((string) $item->ewaste_parts_removed);
                $completeness = $partsRemoved !== ''
                    ? 'Incomplete — parts removed: '.$partsRemoved
                    : 'Incomplete — some parts removed';
            } else {
                $completeness = 'Complete — all parts intact';
            }
        }
    @endphp
    <div class="asset-card">
        <div class="tag">{{ $item->asset_tag }} &mdash; {{ trim(($item->brand ?? '').' '.($item->model ?? '')) ?: '—' }}</div>
        <table class="spec">
            <tr>
                <td class="col">
                    <div class="spec-title">Section A — Identification</div>
                    <table class="kv">
                        <tr><td class="k">Asset Tag</td><td>{{ $item->asset_tag }}</td></tr>
                        <tr><td class="k">Type</td><td>{{ ucfirst(str_replace('_', ' ', $item->asset_type ?? '—')) }}</td></tr>
                        <tr><td class="k">Brand</td><td>{{ $item->brand ?? $a?->brand ?? '—' }}</td></tr>
                        <tr><td class="k">Model</td><td>{{ $item->model ?? $a?->model ?? '—' }}</td></tr>
                        <tr><td class="k">Serial No.</td><td>{{ $item->serial_number ?? '—' }}</td></tr>
                    </table>
                </td>
                <td class="col">
                    <div class="spec-title">Section B — Specification</div>
                    <table class="kv">
                        <tr><td class="k">Processor</td><td>{{ $a?->processor ?? '—' }}</td></tr>
                        <tr><td class="k">RAM</td><td>{{ $a?->ram_size ?? '—' }}</td></tr>
                        <tr><td class="k">Storage</td><td>{{ $a?->storage ?? '—' }}</td></tr>
                        <tr><td class="k">OS</td><td>{{ $a?->operating_system ?? '—' }}</td></tr>
                        <tr><td class="k">Screen</td><td>{{ $a?->screen_size ?? '—' }}</td></tr>
                    </table>
                </td>
            </tr>
        </table>
        @if($condition || $completeness || $specOthers || $notes || $reason)
            <div class="spec-title" style="margin-top:7px;">Condition &amp; Notes</div>
            <table class="kv">
                @if($condition)<tr><td class="k">Condition</td><td>{{ $condition }}</td></tr>@endif
                @if($completeness)<tr><td class="k">Completeness</td><td>{{ $completeness }}</td></tr>@endif
                @if($reason)<tr><td class="k">Decommission Reason</td><td>{{ $reason }}</td></tr>@endif
                @if($specOthers)<tr><td class="k">Other Specs</td><td>{{ $specOthers }}</td></tr>@endif
                @if($notes)<tr><td class="k">Notes</td><td>{!! nl2br(e($notes)) !!}</td></tr>@endif
            </table>
        @endif
        @if(!empty($photos))
            @php
                $embedded = array_values(array_filter(array_map($pubImg, $photos)));
            @endphp
            @if($embedded)
                <div class="photos-title">Asset Photos ({{ count($embedded) }})</div>
                @foreach($embedded as $img)<img class="photo" src="{{ $img }}">@endforeach
            @else
                {{-- Photos are recorded but unreadable (missing from disk / non-image).
                     Say so rather than silently rendering nothing. --}}
                <div class="photos-title">Asset Photos</div>
                <div class="muted" style="font-size:9px;">{{ count($photos) }} photo(s) on record could not be embedded.</div>
            @endif
        @endif
    </div>
    @endforeach

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

    <div class="stamp" style="margin-top:6px;">
        @if($batch->financeApproved())
            <div class="ok">✓ Finance concurred</div>
        @elseif($batch->financeRejected())
            <div style="color:#b91c1c;font-weight:bold;">✗ Finance objected</div>
        @else
            <div class="muted">No Finance position recorded.</div>
        @endif
        @if($reviewer)
            <div style="margin-top:4px;">Reviewed by: <strong>{{ $reviewer['name'] }}</strong></div>
            @if($reviewer['details'])<div class="muted">{{ $reviewer['details'] }}</div>@endif
        @endif
        @if($batch->finance_reviewed_at)
            <div class="muted">Reviewed {{ fmt_datetime($batch->finance_reviewed_at) }}</div>
        @endif
        @if($batch->finance_remarks)<div>Remarks: {{ $batch->finance_remarks }}</div>@endif
        @if($batch->financeRejected() && $batch->management_status === 'approved')
            {{-- An approval over an objection is the single most audit-relevant thing this
                 report can contain. It must be stated, not left to be inferred from two
                 stamps that disagree. --}}
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
